<?php

namespace App\Service\Translation;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Пробует основной движок, при неудаче — запасной.
 *
 * Смысл в том, чтобы недоступность Gemini не останавливала разбор статей:
 * очередь продолжает работать, статья выходит переведённой скрейпером, а не
 * зависает в черновиках. Но деградация обязана быть видимой — молчаливый
 * откат мы уже проходили с OCR, где неудачный запуск tesseract полгода
 * выглядел как «на картинке нет текста», и перевод не сработал ни разу.
 *
 * Поэтому каждое срабатывание запасного движка пишется предупреждением, а имя
 * реально сработавшего движка едет наверх в TranslationResult и дальше в
 * posts.translated_by: по нему видно, какие статьи стоит перевести заново,
 * когда основной движок починится.
 */
class FallbackTranslator implements Translator
{
    public function __construct(
        private readonly Translator $primary,
        private readonly Translator $fallback,
        private readonly ?TranslationDeadline $deadlineHolder = null,
    ) {}

    private function deadline(): TranslationDeadline
    {
        return $this->deadlineHolder ?? app(TranslationDeadline::class);
    }

    public function name(): string
    {
        return $this->primary->name();
    }

    /**
     * Срок на статью ставится ЗДЕСЬ, а не в движке, и держится до конца всей
     * цепочки.
     *
     * Движок его тоже умеет ставить — на случай, когда обёртки нет вовсе
     * (одна модель, откат выключен), — но владеть им в цепочке он не может:
     * звенья работают последовательно, и `translateHtml` основного успевает
     * вернуться и снять срок ДО того, как позовут запасного. Тот ставил бы
     * его заново, и бюджет снова умножался бы на длину цепочки. Ровно это и
     * случилось с постом 236: 152 секунды на первой модели, 332 на второй,
     * третья перевела статью за 52 — но джобу к тому моменту уже убил таймаут,
     * и перевод не успел сохраниться.
     */
    public function translateHtml(string $html): TranslationResult
    {
        $owns = $this->deadline()->start((int) config('translation.gemini.budget_seconds'));

        try {
            return $this->translateHtmlWithinDeadline($html);
        } finally {
            if ($owns) {
                $this->deadline()->stop();
            }
        }
    }

    private function translateHtmlWithinDeadline(string $html): TranslationResult
    {
        if ($this->primaryIsDown()) {
            return $this->fallback->translateHtml($html);
        }

        $result = $this->primary->translateHtml($html);

        // partial — не повод звать запасной: перевод состоялся, просто не
        // целиком. Гонять поверх него скрейпер значит переводить уже
        // переведённое.
        if (! $result->failed) {
            return $result;
        }

        $this->reportFallback('html', $html);

        return $this->fallback->translateHtml($html);
    }

    public function translateText(string $text): TranslationResult
    {
        if ($this->primaryIsDown()) {
            return $this->fallback->translateText($text);
        }

        $result = $this->primary->translateText($text);

        if (! $result->failed) {
            return $result;
        }

        $this->reportFallback('text', $text);

        return $this->fallback->translateText($text);
    }

    /**
     * Основной движок помечен нерабочим и пока не трогается.
     *
     * Флаг ставит сам движок, когда получил ответ, который повтором не
     * исправить: нет ключа, регион не поддерживается, доступ отозван. Без
     * паузы пакетная обработка выжидает полный таймаут на каждой статье —
     * сотня постов в очереди превращается в часы простоя ради заведомо
     * провальных запросов.
     */
    private function primaryIsDown(): bool
    {
        return self::isDown($this->primary->name());
    }

    /**
     * Разомкнут ли сейчас движок.
     *
     * Публично — ради плитки состояния в админке: до неё узнать, что перевод
     * молча ушёл на скрейпер, можно было только из laravel.log. Ключ кэша
     * остаётся приватным намеренно, иначе он немедленно расползётся по
     * вызывающим и разъедется с markDown().
     */
    public static function isDown(string $engine): bool
    {
        $seconds = (int) config('translation.circuit_breaker_seconds');

        if ($seconds <= 0) {
            return false;
        }

        return self::store()->has(self::downKey($engine));
    }

    /**
     * Помечает движок нерабочим на время паузы.
     *
     * $seconds задаётся вызывающим там, где он знает про беду больше нас:
     * исчерпанная суточная квота лечится не пятью минутами, а следующими
     * сутками. Значение из конфига остаётся значением по умолчанию, а сам
     * конфиг — главным рубильником: ноль отключает размыкатель целиком, каким
     * бы ни был аргумент.
     */
    public static function markDown(string $engine, ?int $seconds = null): void
    {
        $default = (int) config('translation.circuit_breaker_seconds');

        // Конфиг — главный рубильник: ноль отключает размыкатель целиком,
        // каким бы ни был аргумент.
        if ($default <= 0) {
            return;
        }

        $seconds = $seconds !== null && $seconds > 0 ? $seconds : $default;
        $until = now()->addSeconds($seconds);
        $key = self::downKey($engine);

        // Уже размокнутый движок не «чинится» более коротким отказом. Иначе
        // рядовая пятиминутная пауза, легшая поверх часовой квотной, вернула
        // бы нас к заведомо провальным попыткам через пять минут — и так по
        // кругу до самого сброса квоты.
        $until = max($until->timestamp, (int) self::store()->get($key, 0));

        self::store()->put($key, $until, CarbonImmutable::createFromTimestamp($until));

        Log::warning('Перевод: движок помечен нерабочим', [
            'engine' => $engine,
            'seconds' => $seconds,
            'until' => CarbonImmutable::createFromTimestamp($until)->toDateTimeString(),
        ]);
    }

    /**
     * Состояние предохранителя живёт отдельно от общего кэша: `cache:clear` в
     * деплое чистит только хранилище по умолчанию, и каждый выкат сбрасывал
     * паузы у моделей с исчерпанной квотой (см. config/translation.php).
     */
    private static function store(): Repository
    {
        $store = (string) config('translation.circuit_breaker_store');

        return Cache::store($store !== '' ? $store : null);
    }

    private static function downKey(string $engine): string
    {
        return "translation:down:{$engine}";
    }

    private function reportFallback(string $kind, string $source): void
    {
        Log::warning('Перевод: основной движок не справился, переключаюсь на запасной', [
            'primary' => $this->primary->name(),
            'fallback' => $this->fallback->name(),
            'kind' => $kind,
            'excerpt' => mb_substr($source, 0, 150),
        ]);
    }
}

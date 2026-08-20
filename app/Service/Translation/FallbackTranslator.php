<?php

namespace App\Service\Translation;

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
    ) {}

    public function name(): string
    {
        return $this->primary->name();
    }

    public function translateHtml(string $html): TranslationResult
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
        $seconds = (int) config('translation.circuit_breaker_seconds');

        if ($seconds <= 0) {
            return false;
        }

        return Cache::has(self::downKey($this->primary->name()));
    }

    /**
     * Помечает движок нерабочим на время паузы.
     */
    public static function markDown(string $engine): void
    {
        $seconds = (int) config('translation.circuit_breaker_seconds');

        if ($seconds <= 0) {
            return;
        }

        Cache::put(self::downKey($engine), true, $seconds);

        Log::warning('Перевод: движок помечен нерабочим', [
            'engine' => $engine,
            'seconds' => $seconds,
        ]);
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

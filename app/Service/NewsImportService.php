<?php

namespace App\Service;

use App\Jobs\StorePostJob;
use App\Models\Post;
use App\Support\NewsDigestParser;
use Illuminate\Support\Facades\Log;

/**
 * Импорт новостей из секции дайджеста PHP Weekly.
 *
 * Сервис только находит ссылки и ставит задачи — разбором занимается тот же
 * StorePostJob, что и для статей. Поэтому у новости есть всё, что есть у
 * поста: полный переведённый текст, локальные картинки, обход antibot
 * (RSS и headless), ретраи, пост-заглушка с причиной при сбое и своя
 * страница. Отличает её только флаг is_news.
 *
 * Дублировать пайплайн ради отдельной модели было бы ошибкой: каждая правка
 * в нём делалась бы дважды.
 */
class NewsImportService
{
    public function __construct(
        private readonly ReleaseService $releaseService = new ReleaseService
    ) {}

    /**
     * @return array{dispatched: int, skipped: int, exhausted: int}
     */
    public function importFromDigest(string $digestUrl): array
    {
        $html = $this->releaseService->fetchHtmlContent($digestUrl);
        $items = NewsDigestParser::parse($html, $this->sectionHeading());

        if ($items === []) {
            Log::warning('NewsImport: секция не найдена или пуста', [
                'digest' => $digestUrl,
                'section' => $this->sectionHeading(),
            ]);

            // Полный набор ключей: потребитель складывает статистику по
            // фиксированным именам, и ранний выход без exhausted ронял бы её
            // «Undefined array key» ровно на пустой секции.
            return ['dispatched' => 0, 'skipped' => 0, 'exhausted' => 0];
        }

        $stats = ['dispatched' => 0, 'skipped' => 0, 'exhausted' => 0];

        foreach ($items as $item) {
            // Уже разобранное не трогаем: повторный разбор — это внешний фетч,
            // перевод и скачивание картинок заново. Неудачные заглушки
            // (parse_status = failed) сознательно пробуем ещё раз.
            $existing = Post::where('url', $item['url'])->first();

            if ($existing !== null && $existing->parse_status !== Post::PARSE_STATUS_FAILED) {
                $stats['skipped']++;

                continue;
            }

            if ($existing !== null && ! $this->worthRetrying($existing)) {
                $stats['exhausted']++;

                continue;
            }

            // Считаем попытку здесь, а не в джобе: повторы устраивает этот
            // сервис, а StorePostJob про предыдущие запуски не знает — ей
            // пришлось бы читать пост из БД ради инкремента. Считаем ДО
            // отправки: упавшая или потерянная задача — тоже израсходованная
            // попытка, иначе ссылка, которая роняет воркер, крутилась бы
            // вечно с нулевым счётчиком.
            //
            // Плата за это — у StorePostJob есть uniqueId() с окном 15 минут,
            // и два ручных запуска подряд спишут две попытки, а разбор
            // случится один. Пока команда ходила по расписанию раз в сутки,
            // случая не было; при ручных прогонах держите паузу либо
            // поднимите releases.news_retry_limit.
            $existing?->increment('parse_attempts');

            StorePostJob::dispatch([
                'url' => $item['url'],
                // Пустой селектор джоба разрешит сама по домену — правила
                // из админки и конфига.
                'selector' => '',
                'tag_ids' => [],
                'translate' => null,
                'is_news' => true,
                // Заголовок из дайджеста — запасной: если страница не отдаст
                // <h1>, ссылка не потеряется и в админке будет видно, что это.
                'link_text' => $item['title'],
            ]);

            $stats['dispatched']++;
        }

        Log::info('NewsImport: задачи поставлены', $stats + ['digest' => $digestUrl]);

        return $stats;
    }

    /**
     * Стоит ли дать заглушке ещё одну попытку.
     *
     * «Пробуем ещё раз» без потолка — это не второй шанс, а вечный цикл.
     * Временная беда (антибот, таймаут, лежащий источник) проходит за пару
     * заходов; постоянная — ролик на YouTube, главная php.net, страница
     * релиза на GitHub — не пройдёт никогда, а каждая попытка стоит внешнего
     * запроса и, если страница отдаст заголовок, обращения к модели.
     *
     * Ссылка при этом не теряется: заглушка остаётся в админке с причиной
     * сбоя, и её всегда можно перезапустить кнопкой «Разобрать заново» —
     * ручной повтор этот счётчик не смотрит.
     */
    private function worthRetrying(Post $post): bool
    {
        $limit = (int) config('releases.news_retry_limit', 3);

        // Ноль или отрицательное значение выключает потолок, а не запрещает
        // повторы: «ограничения нет» — единственное осмысленное прочтение, и
        // оно совпадает с поведением до появления счётчика.
        if ($limit <= 0) {
            return true;
        }

        return $post->parse_attempts < $limit;
    }

    /**
     * Заголовок секции в дайджесте. В конфиге, потому что у соседних секций
     * («Tutorials and Talks», «Interesting Projects») структура та же и
     * импорт переиспользуется сменой значения.
     */
    private function sectionHeading(): string
    {
        return (string) config('releases.news_section_heading', 'News and Announcements');
    }
}

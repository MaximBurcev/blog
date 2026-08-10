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
     * @return array{dispatched: int, skipped: int}
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

            return ['dispatched' => 0, 'skipped' => 0];
        }

        $stats = ['dispatched' => 0, 'skipped' => 0];

        foreach ($items as $item) {
            // Уже разобранное не трогаем: повторный разбор — это внешний фетч,
            // перевод и скачивание картинок заново. Неудачные заглушки
            // (parse_status = failed) сознательно пробуем ещё раз.
            $existing = Post::where('url', $item['url'])->first();

            if ($existing !== null && $existing->parse_status !== Post::PARSE_STATUS_FAILED) {
                $stats['skipped']++;

                continue;
            }

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
     * Заголовок секции в дайджесте. В конфиге, потому что у соседних секций
     * («Tutorials and Talks», «Interesting Projects») структура та же и
     * импорт переиспользуется сменой значения.
     */
    private function sectionHeading(): string
    {
        return (string) config('releases.news_section_heading', 'News and Announcements');
    }
}

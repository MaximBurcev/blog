<?php

namespace App\Service;

use App\Models\Tool;
use App\Service\Translation\TranslationResult;
use App\Service\Translation\Translator;
use App\Support\NewsDigestParser;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ToolImportService
{
    private const MAX_URL_LENGTH = 255;

    private const MAX_NAME_LENGTH = 255;

    private const MAX_DIGEST_URL_LENGTH = 2048;

    private const MIN_SUMMARY_THRESHOLD = 1;

    public function __construct(
        private readonly Translator $translator,
        private readonly ReleaseService $releaseService = new ReleaseService,
    ) {}

    /**
     * @return array{found: int, created: int, skipped: int, rejected: int, translated: int}
     */
    public function importFromDigest(string $digestUrl): array
    {
        $html = $this->releaseService->fetchHtmlContent($digestUrl);
        $items = NewsDigestParser::parse($html, $this->sectionHeading(), $this->minSummaryLength());

        $stats = ['found' => count($items), 'created' => 0, 'skipped' => 0, 'rejected' => 0, 'translated' => 0];

        if ($items === []) {
            Log::warning('ToolImport: секция не найдена или пуста', [
                'digest' => $digestUrl,
                'section' => $this->sectionHeading(),
            ]);

            return $stats;
        }

        $fresh = $this->rejectKnown($items, $stats);
        $translations = $this->translateAll(array_column($fresh, 'summary'));

        foreach ($fresh as $index => $item) {
            $translation = $translations[$index] ?? null;

            $tool = Tool::firstOrCreate(
                ['url' => $item['url']],
                [
                    'name' => mb_substr($item['title'], 0, self::MAX_NAME_LENGTH),
                    'description' => $translation['text'] ?? null,
                    'description_orig' => $item['summary'],
                    'digest_url' => mb_substr($digestUrl, 0, self::MAX_DIGEST_URL_LENGTH),
                    'translated_by' => $translation['engine'] ?? null,
                ]
            );

            if (! $tool->wasRecentlyCreated) {
                $stats['skipped']++;

                continue;
            }

            $stats['created']++;

            if ($translation !== null) {
                $stats['translated']++;
            }
        }

        Log::info('ToolImport: инструменты импортированы', $stats + ['digest' => $digestUrl]);

        return $stats;
    }

    /**
     * @return array{found: int, translated: int}
     */
    public function translatePending(int $limit = 50): array
    {
        $pending = Tool::query()
            ->whereNull('description')
            ->whereNotNull('description_orig')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($pending->isEmpty()) {
            return ['found' => 0, 'translated' => 0];
        }

        $translations = $this->translateAll($pending->pluck('description_orig')->all());

        $translated = 0;

        foreach ($pending->values() as $index => $tool) {
            if (! isset($translations[$index])) {
                continue;
            }

            $tool->update([
                'description' => $translations[$index]['text'],
                'translated_by' => $translations[$index]['engine'],
            ]);

            $translated++;
        }

        Log::info('ToolImport: догоняющий перевод', ['found' => $pending->count(), 'translated' => $translated]);

        return ['found' => $pending->count(), 'translated' => $translated];
    }

    /**
     * @param  array<int, array{title: string, url: string, summary: string}>  $items
     * @param  array{found: int, created: int, skipped: int, rejected: int, translated: int}  $stats
     * @return list<array{title: string, url: string, summary: string}>
     */
    private function rejectKnown(array $items, array &$stats): array
    {
        $known = Tool::withTrashed()
            ->whereIn('url', array_column($items, 'url'))
            ->pluck('url')
            ->flip();

        $fresh = [];

        foreach ($items as $item) {
            if (mb_strlen($item['url']) > self::MAX_URL_LENGTH) {
                Log::warning('ToolImport: ссылка длиннее колонки', [
                    'url' => mb_substr($item['url'], 0, 120),
                ]);
                $stats['rejected']++;

                continue;
            }

            if ($known->has($item['url'])) {
                $stats['skipped']++;

                continue;
            }

            $known->put($item['url'], true);
            $fresh[] = $item;
        }

        return $fresh;
    }

    /**
     * Пачка экономит квоту, поштучный проход доводит дело до скрейпера: тот
     * не понимает пачку целиком, но переводит отдельную строку и квоты не ест.
     *
     * @param  list<string>  $summaries
     * @return array<int, array{text: string, engine: string}>
     */
    private function translateAll(array $summaries): array
    {
        if ($summaries === []) {
            return [];
        }

        $translations = $this->translateAsList($summaries);

        foreach ($summaries as $index => $summary) {
            if (isset($translations[$index])) {
                continue;
            }

            if ($accepted = $this->accept($this->translator->translateText($summary), $summary)) {
                $translations[$index] = $accepted;
            }
        }

        if ($translations === []) {
            Log::warning('ToolImport: описания остались на английском', ['count' => count($summaries)]);
        }

        return $translations;
    }

    /**
     * @param  list<string>  $summaries
     * @return array<int, array{text: string, engine: string}>
     */
    private function translateAsList(array $summaries): array
    {
        $list = '<ul>'.implode('', array_map(
            static fn (string $summary): string => '<li>'.htmlspecialchars(
                $summary,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ).'</li>',
            $summaries
        )).'</ul>';

        $result = $this->translator->translateHtml($list);

        if ($result->failed) {
            return [];
        }

        $texts = $this->extractListItems($result->text);

        // Соответствие держится на позиции, поэтому другое число пунктов —
        // это не «часть потерялась», а «неизвестно, что чему принадлежит»:
        // разобрав такой ответ, мы бы приписали описание чужому пакету.
        if (count($texts) !== count($summaries)) {
            Log::warning('ToolImport: в переводе другое число пунктов', [
                'expected' => count($summaries),
                'got' => count($texts),
            ]);

            return [];
        }

        $translations = [];

        foreach ($summaries as $index => $summary) {
            $accepted = $this->accept(
                TranslationResult::success($texts[$index], $result->engine),
                $summary
            );

            if ($accepted) {
                $translations[$index] = $accepted;
            }
        }

        return $translations;
    }

    /**
     * @return array{text: string, engine: string}|null
     */
    private function accept(TranslationResult $result, string $original): ?array
    {
        $text = trim($result->text);

        if ($result->failed || $text === '' || $text === trim($original)) {
            return null;
        }

        return ['text' => $text, 'engine' => $result->engine];
    }

    /**
     * @return list<string>
     */
    private function extractListItems(string $html): array
    {
        $crawler = new Crawler;
        $crawler->addHtmlContent('<div>'.$html.'</div>', 'UTF-8');

        return $crawler->filter('li')->each(static fn (Crawler $li): string => trim($li->text()));
    }

    private function sectionHeading(): string
    {
        return (string) config(
            'releases.tools_section_heading',
            'Interesting Projects, Tools and Libraries'
        );
    }

    private function minSummaryLength(): int
    {
        return max(
            self::MIN_SUMMARY_THRESHOLD,
            (int) config('releases.tools_min_summary_length', 20)
        );
    }
}

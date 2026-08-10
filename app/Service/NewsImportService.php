<?php

namespace App\Service;

use App\Models\News;
use App\Support\NewsDigestParser;
use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

/**
 * Импорт новостей из секции дайджеста PHP Weekly.
 *
 * В отличие от статей, ничего не скачивается по ссылке: заголовок и описание
 * уже есть в самом дайджесте. Это и надёжнее (нет Cloudflare, разнобоя вёрстки
 * и заглушек), и честнее по отношению к источнику — читатель уходит читать
 * оригинал.
 */
class NewsImportService
{
    /**
     * Заголовок секции в дайджесте. Вынесен в конфиг, потому что у соседних
     * секций («Tutorials and Talks», «Interesting Projects») структура та же
     * и импорт можно переиспользовать.
     */
    private function sectionHeading(): string
    {
        return (string) config('releases.news_section_heading', 'News and Announcements');
    }

    public function __construct(
        private readonly ReleaseService $releaseService = new ReleaseService
    ) {}

    /**
     * @return array{imported: int, updated: int, skipped: int}
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

            return ['imported' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $translator = $this->makeTranslator();
        $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($items as $item) {
            // Уже импортированное не переводим заново: перевод — самая
            // дорогая часть, а дайджесты частично повторяют друг друга.
            if (News::where('url', $item['url'])->exists()) {
                $stats['skipped']++;

                continue;
            }

            $incomplete = false;

            $title = $this->translate($translator, $item['title'], $incomplete);
            $summary = $this->translate($translator, $item['summary'], $incomplete);

            News::create([
                'url' => $item['url'],
                'title' => $title,
                'title_orig' => $item['title'],
                'summary' => $summary,
                'summary_orig' => $item['summary'],
                'source_host' => parse_url($item['url'], PHP_URL_HOST) ?: null,
                'published' => true,
                'translation_incomplete' => $incomplete,
            ]);

            $stats['imported']++;
        }

        Log::info('NewsImport: импорт завершён', $stats + ['digest' => $digestUrl]);

        return $stats;
    }

    /**
     * Перевод с мягкой деградацией: сбой переводчика (лимит, сеть) не должен
     * терять новость — сохраняем оригинал и помечаем запись для ревью, ровно
     * как это делает пайплайн статей.
     */
    private function translate(GoogleTranslate $translator, string $text, bool &$incomplete): string
    {
        if ($text === '') {
            return $text;
        }

        try {
            $translated = $translator->translate($text);
        } catch (\Throwable $exception) {
            Log::warning('NewsImport: перевод не удался, оставляем оригинал', [
                'error' => mb_substr($exception->getMessage(), 0, 120),
            ]);
            $incomplete = true;

            return $text;
        }

        if (! is_string($translated) || trim($translated) === '') {
            $incomplete = true;

            return $text;
        }

        return $translated;
    }

    private function makeTranslator(): GoogleTranslate
    {
        $translator = new GoogleTranslate('ru');

        if ($proxy = config('releases.curl_proxy')) {
            $translator->setOptions(['proxy' => 'socks5://'.$proxy]);
        }

        return $translator;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\StorePostJob;
use Illuminate\Console\Command;

class ParsePostCommand extends Command
{
    protected $signature = 'post:parse
        {url : URL статьи для парсинга}
        {--selector= : CSS-класс блока с контентом (по умолчанию определяется по домену)}
        {--html-file= : Путь к локальному HTML-файлу (не скачивать через curl)}
        {--sync : Выполнить синхронно, не через очередь}';

    protected $description = 'Парсит статью по URL и создаёт пост';

    public function handle(): int
    {
        $url = $this->argument('url');
        $selector = $this->option('selector') ?: $this->getSelectorForUrl($url);

        $this->info("Селектор: {$selector}");

        $data = [
            'url'       => $url,
            'selector'  => $selector,
            'tag_ids'   => [],
            'translate' => null,
        ];

        if ($this->option('html-file')) {
            $data['html_file'] = $this->option('html-file');
        }

        if ($this->option('sync')) {
            $this->info("Парсинг (синхронно): {$url}");
            StorePostJob::dispatchSync($data);
            $this->info('Готово.');
        } else {
            $this->info("Отправка в очередь: {$url}");
            StorePostJob::dispatch($data);
            $this->info('Джоб отправлен. Запустите queue:work для обработки.');
        }

        return self::SUCCESS;
    }

    private function getSelectorForUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        foreach (config('releases.domain_selectors', []) as $domain => $selector) {
            if (str_contains($host, $domain)) {
                return $selector;
            }
        }

        return config('releases.post_selector', 'article-body');
    }
}

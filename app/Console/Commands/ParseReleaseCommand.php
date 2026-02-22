<?php

namespace App\Console\Commands;

use App\Service\ReleaseService;
use Illuminate\Console\Command;

class ParseReleaseCommand extends Command
{
    protected $signature = 'release:parse {url : URL страницы с релизом}';

    protected $description = 'Парсит ссылки со страницы релиза и запускает StorePostJob для каждой';

    public function handle(ReleaseService $service): int
    {
        $url = $this->argument('url');

        $this->info("Парсинг: {$url}");

        try {
            $links = $service->addPosts($url);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if (empty($links)) {
            $this->warn('Ссылки не найдены.');
            return self::SUCCESS;
        }

        $this->info('Найдено ссылок: ' . count($links));
        $this->table(['URL', 'Текст'], array_map(
            fn($link) => [$link['url'], $link['text'] ?? ''],
            $links
        ));

        return self::SUCCESS;
    }
}

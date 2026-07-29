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
            // Сохраняем сам релиз (идемпотентно по url), затем парсим ссылки —
            // так же, как админский флоу Admin\Release\StoreController.
            $release = $service->store(['url' => $url]);
            $this->info($release->wasRecentlyCreated
                ? "Релиз #{$release->id} создан."
                : "Релиз #{$release->id} уже существует.");

            $links = $service->addPosts($release->url);
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (empty($links)) {
            $this->warn('Ссылки не найдены.');

            return self::SUCCESS;
        }

        $this->info('Найдено ссылок: '.count($links));
        $this->table(['URL', 'Текст'], array_map(
            fn ($link) => [$link['url'], $link['text'] ?? ''],
            $links
        ));

        return self::SUCCESS;
    }
}

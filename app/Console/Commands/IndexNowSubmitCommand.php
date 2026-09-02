<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Service\IndexNowService;
use Illuminate\Console\Command;

/**
 * Первичная отправка уже опубликованных страниц в IndexNow.
 *
 * Публикация шлёт пинг сама (хук Post::saved), но посты, вышедшие до
 * появления интеграции, поисковикам так и не сообщены — их и покрывает
 * --all. Повторная отправка одних и тех же адресов протоколом не наказуема.
 */
class IndexNowSubmitCommand extends Command
{
    protected $signature = 'indexnow:submit {url? : Адрес одной страницы} {--all : Все опубликованные посты}';

    protected $description = 'Отправить адреса страниц в IndexNow (Яндекс, Bing)';

    public function handle(IndexNowService $indexNow): int
    {
        if (! config('indexnow.enabled') || ! filled(config('indexnow.key'))) {
            $this->warn('IndexNow выключен или INDEXNOW_KEY не задан — отправка не выполнялась.');

            return self::FAILURE;
        }

        if ($this->option('all')) {
            $urls = Post::query()->published()->get()->map->permalink()->all();

            $this->info('Отправляем опубликованных: '.count($urls));

            return $indexNow->submit($urls) ? self::SUCCESS : self::FAILURE;
        }

        $url = (string) $this->argument('url');

        if ($url === '') {
            $this->error('Укажите url или --all');

            return self::FAILURE;
        }

        return $indexNow->submit([$url]) ? self::SUCCESS : self::FAILURE;
    }
}

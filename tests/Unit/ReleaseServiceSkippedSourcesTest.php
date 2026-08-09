<?php

namespace Tests\Unit;

use App\Service\ReleaseService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Ссылки на не-статьи (видео, репозитории, справочники) регулярно попадают
 * в дайджесты. Парсер честно их скачивал, не находил контент по селектору и
 * создавал пост-заглушку: 7 из 13 заглушек в проде на 2026-08-09 пришли
 * именно оттуда — youtube (2), github, php.net, podcast.laravel-news.com.
 * Теперь такие ссылки отсеиваются до постановки джобы.
 */
class ReleaseServiceSkippedSourcesTest extends TestCase
{
    private function processLinks(array $links): array
    {
        $method = new \ReflectionMethod(ReleaseService::class, 'processLinks');
        $method->setAccessible(true);

        return $method->invoke(new ReleaseService, $links);
    }

    private function link(string $url): array
    {
        return ['text' => 'Заголовок', 'url' => $url];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('releases.skipped_domains', ['youtube.com', 'github.com', 'php.net']);
        Config::set('releases.offset', 0);
        Config::set('releases.max_links', 20);
    }

    public function test_skips_non_article_sources(): void
    {
        $result = $this->processLinks([
            $this->link('https://dev.to/some/article'),
            $this->link('https://www.youtube.com/watch?v=abc'),
            $this->link('https://github.com/laravel/framework'),
            $this->link('https://www.php.net/manual/ru/function.array-map.php'),
            $this->link('https://medium.com/@user/real-article'),
        ]);

        $urls = array_column($result, 'url');

        $this->assertSame([
            'https://dev.to/some/article',
            'https://medium.com/@user/real-article',
        ], $urls);
    }

    public function test_lookalike_domain_is_not_skipped(): void
    {
        $result = $this->processLinks([
            $this->link('https://youtube.com.evil.tld/article'),
        ]);

        // Совпадение по границе метки: подделка под youtube.com статьёй
        // считаться должна (её отсеет уже UrlSafetyChecker, если нужно).
        $this->assertCount(1, $result);
    }

    /**
     * Отсев обязан идти ДО среза по offset/max_links, иначе видео и
     * репозитории занимают слоты вместо статей.
     */
    public function test_skipped_links_do_not_consume_max_links_slots(): void
    {
        Config::set('releases.max_links', 2);

        $result = $this->processLinks([
            $this->link('https://www.youtube.com/watch?v=1'),
            $this->link('https://github.com/a/b'),
            $this->link('https://dev.to/first'),
            $this->link('https://dev.to/second'),
        ]);

        $this->assertSame(
            ['https://dev.to/first', 'https://dev.to/second'],
            array_column($result, 'url')
        );
    }

    public function test_duplicates_are_still_removed(): void
    {
        $result = $this->processLinks([
            $this->link('https://dev.to/one'),
            $this->link('https://dev.to/one'),
            $this->link('https://dev.to/two'),
        ]);

        $this->assertCount(2, $result);
    }
}

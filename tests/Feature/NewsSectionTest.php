<?php

namespace Tests\Feature;

use App\Models\News;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Раздел новостей: лента + модель. Новости приходят из секции
 * «News and Announcements» дайджеста PHP Weekly — заголовок и описание
 * переводятся, читатель уходит по ссылке на первоисточник.
 */
class NewsSectionTest extends TestCase
{
    use RefreshDatabase;

    private function news(array $attributes = []): News
    {
        return News::create(array_merge([
            'url' => 'https://thephp.foundation/blog/'.uniqid().'/',
            'title' => 'Ежеквартальный отчёт',
            'title_orig' => 'Quarterly Report',
            'summary' => 'Команда разработчиков ядра работает над улучшением языка PHP.',
            'summary_orig' => 'The core team works on improving PHP.',
            'source_host' => 'thephp.foundation',
            'published' => true,
        ], $attributes));
    }

    public function test_listing_shows_published_news(): void
    {
        $this->news(['title' => 'Видимая новость']);

        $this->get(route('news.index'))
            ->assertOk()
            ->assertSee('Видимая новость')
            ->assertSee('thephp.foundation');
    }

    public function test_listing_hides_unpublished_news(): void
    {
        $this->news(['title' => 'Скрытая новость', 'published' => false]);

        $this->get(route('news.index'))
            ->assertOk()
            ->assertDontSee('Скрытая новость');
    }

    public function test_listing_links_to_the_original_source(): void
    {
        $this->news(['url' => 'https://laravel-news.com/some-release']);

        $this->get(route('news.index'))
            ->assertOk()
            ->assertSee('https://laravel-news.com/some-release', escape: false)
            // Ссылки добавляются автоматически и ведут наружу — вес им
            // передавать не нужно.
            ->assertSee('nofollow', escape: false);
    }

    public function test_listing_is_reachable_from_the_menu(): void
    {
        $this->get('/')->assertOk()->assertSee(route('news.index'), escape: false);
    }

    public function test_empty_listing_does_not_break(): void
    {
        $this->get(route('news.index'))->assertOk()->assertSee('Новостей пока нет');
    }

    /**
     * Описание приходит из чужого письма и выводится на публичной странице —
     * мутатор гонит его через тот же санитайзер, что и контент постов.
     */
    public function test_summary_is_sanitized(): void
    {
        $item = $this->news([
            'summary' => 'Текст <script>alert(1)</script> и <img src=x onerror=alert(2)> дальше.',
        ]);

        $this->assertStringNotContainsString('<script', $item->fresh()->summary);
        $this->assertStringNotContainsString('onerror', $item->fresh()->summary);

        $this->get(route('news.index'))->assertOk()->assertDontSee('alert(1)', escape: false);
    }

    /**
     * url — ключ дедупликации: повторный импорт того же дайджеста не должен
     * задваивать новость. UNIQUE в схеме, а не только проверка в сервисе,
     * на случай гонки двух воркеров.
     */
    public function test_url_is_unique(): void
    {
        $this->news(['url' => 'https://example.test/same']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->news(['url' => 'https://example.test/same']);
    }

    public function test_sitemap_includes_the_feed_only_when_there_is_news(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertDontSee(route('news.index'), escape: false);

        $this->news();

        $this->get('/sitemap.xml')->assertOk()->assertSee(route('news.index'), escape: false);
    }
}

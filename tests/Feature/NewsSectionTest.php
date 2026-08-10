<?php

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Новость — тот же Post с флагом is_news: разбирается, переводится и хранится
 * ровно тем же пайплайном, что и статья, поэтому у неё есть полный текст,
 * картинки и своя страница. Отдельная модель означала бы дублирование всего
 * StorePostJob — фетча с SSRF-проверкой, обхода antibot, ретраев, заглушек.
 *
 * Тесты фиксируют главное следствие: две ленты не смешиваются.
 */
class NewsSectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Не post(): в Laravel 13 у Illuminate\Foundation\Testing\TestCase есть
     * публичный post() для HTTP-запросов, и одноимённый приватный метод
     * роняет класс на fatal.
     */
    private function makePost(array $attributes = []): Post
    {
        static $n = 0;
        $n++;

        return Post::create(array_merge([
            'title' => 'Заголовок '.$n,
            'content' => '<p>Текст записи '.$n.'.</p>',
            'code' => 'zapis-'.$n,
            'published' => true,
            'is_news' => false,
        ], $attributes));
    }

    public function test_news_listing_shows_only_news(): void
    {
        $this->makePost(['title' => 'Обычная статья']);
        $this->makePost(['title' => 'Свежая новость', 'is_news' => true]);

        $this->get(route('news.index'))
            ->assertOk()
            ->assertSee('Свежая новость')
            ->assertDontSee('Обычная статья');
    }

    public function test_main_feed_excludes_news(): void
    {
        $this->makePost(['title' => 'Обычная статья']);
        $this->makePost(['title' => 'Свежая новость', 'is_news' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Обычная статья')
            ->assertDontSee('Свежая новость');
    }

    public function test_news_listing_hides_unpublished(): void
    {
        $this->makePost(['title' => 'Черновик новости', 'is_news' => true, 'published' => false]);

        $this->get(route('news.index'))->assertOk()->assertDontSee('Черновик новости');
    }

    /**
     * У новости полноценная страница — тот же адрес, что у статьи. Второй URL
     * под тот же контент заводить нельзя: это дубль для поисковика.
     */
    public function test_news_has_a_full_page(): void
    {
        $news = $this->makePost([
            'title' => 'Драйвер журнала в Laravel',
            'content' => '<p>Полный переведённый текст новости.</p>',
            'is_news' => true,
        ]);

        $this->get(route('news.index'))
            ->assertOk()
            ->assertSee(route('post.show', $news->code), escape: false);

        $this->get(route('post.show', $news->code))
            ->assertOk()
            ->assertSee('Полный переведённый текст новости');
    }

    public function test_empty_listing_does_not_break(): void
    {
        $this->get(route('news.index'))->assertOk()->assertSee('Новостей пока нет');
    }

    public function test_listing_is_reachable_from_the_menu(): void
    {
        $this->get('/')->assertOk()->assertSee(route('news.index'), escape: false);
    }

    /**
     * Лента новостей попадает в карту сайта, только когда в ней что-то есть:
     * пустой раздел — приглашение поисковику на пустую страницу.
     */
    public function test_sitemap_includes_the_feed_only_when_there_is_news(): void
    {
        $this->makePost(['title' => 'Только статья']);

        $this->get('/sitemap.xml')->assertOk()->assertDontSee(route('news.index'), escape: false);

        $this->makePost(['is_news' => true]);

        $this->get('/sitemap.xml')->assertOk()->assertSee(route('news.index'), escape: false);
    }

    public function test_scopes_split_the_two_feeds(): void
    {
        $this->makePost(['is_news' => false]);
        $this->makePost(['is_news' => true]);
        $this->makePost(['is_news' => true]);

        $this->assertSame(1, Post::articles()->count());
        $this->assertSame(2, Post::news()->count());
    }
}

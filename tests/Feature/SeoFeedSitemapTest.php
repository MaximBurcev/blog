<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SeoFeedSitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_lists_published_posts_only(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            Post::create([
                'title' => 'Draft post',
                'code' => 'draft-post',
                'content' => 'content',
                'published' => 0,
                'category_id' => $category->id,
            ]);
        });

        $response = $this->get(route('sitemap.xml'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $response->assertSee(route('post.show', 'published-post'), false);
        $response->assertDontSee(route('post.show', 'draft-post'), false);
    }

    public function test_feed_lists_published_posts_only(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            Post::create([
                'title' => 'Draft post',
                'code' => 'draft-post',
                'content' => 'content',
                'published' => 0,
                'category_id' => $category->id,
            ]);
        });

        $response = $this->get(route('feed.index'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->assertSee('Published post');
        $response->assertDontSee('Draft post');
    }

    /**
     * Вебмастер жаловался на «Отсутствуют метатеги Description»: под
     * layouts.app метатега не было вовсе, а Post::excerpt() умеет вернуть
     * пустую строку.
     *
     * Адреса без параметров собираются из таблицы маршрутов, а не
     * перечисляются руками: ровно мимо рукописного списка прошла демо-страница
     * /counter из туториала Livewire — 200, открыта для индексации и <head>
     * без единого метатега. Параметрические адреса задать автоматически
     * нечем — они по-прежнему перечислены ниже поимённо.
     */
    public function test_every_public_page_has_non_empty_meta_description(): void
    {
        $category = null;

        Post::withoutSyncingToSearch(function () use (&$category) {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                // Один лишь листинг: excerpt() выбрасывает <pre> и остаётся ни с чем.
                'content' => '<pre><code>echo 1;</code></pre>',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            Post::create([
                'title' => 'Published news',
                'code' => 'published-news',
                'content' => '<pre><code>echo 2;</code></pre>',
                'published' => 1,
                'is_news' => 1,
                'category_id' => $category->id,
            ]);
        });

        // Страницы с параметрами в адресе таблица маршрутов за нас заполнить не
        // может — и именно они интереснее прочих (описание берётся из текста
        // материала), поэтому тут никаких послаблений: строго 200 и метатег.
        foreach ([
            route('post.show', 'published-post'),
            route('news.show', 'published-news'),
            route('category.show', $category->code),
        ] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertNotSame('', $this->metaDescription($response->getContent()),
                "Пустой или отсутствующий meta description: {$url}");
        }

        $checked = [];

        foreach ($this->parameterlessGetUrls() as $url) {
            $response = $this->get($url);

            // Метатеги бывают только у страниц: редиректы (/posts), XML-выдача
            // (sitemap, RSS) и закрытые для гостя разделы сюда не относятся.
            if ($response->status() !== 200
                || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                continue;
            }

            $this->assertNotSame('', $this->metaDescription($response->getContent()),
                "Пустой или отсутствующий meta description: {$url}");

            $checked[] = $url;
        }

        // Без этого обход остаётся зелёным, даже если из него выпало всё:
        // и сборка списка, и пропуск по статусу отсеивают адреса молча.
        foreach ([route('main.index'), route('news.index'), route('login')] as $url) {
            $this->assertContains($url, $checked, "Страница выпала из обхода: {$url}");
        }
    }

    /**
     * Адреса всех GET-маршрутов приложения, которым не нужны параметры.
     *
     * Чужие маршруты отбираются по namespace обработчика, а не по списку
     * префиксов: список пришлось бы дописывать после каждого composer require,
     * а до тех пор тест падал бы на разметке Horizon или Pulse. Замыкания
     * (обработчика-класса у них нет) — это всегда код из routes/.
     */
    private function parameterlessGetUrls(): array
    {
        $urls = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
                continue;
            }

            $handler = $route->getAction('controller');

            // Livewire-компонент тоже подходит: демо-страница /counter, ради
            // которой этот обход и появился, была не контроллером, а
            // App\Livewire\Counter.
            if (is_string($handler) && ! str_starts_with($handler, 'App\\')) {
                continue;
            }

            $urls[] = url(ltrim($route->uri(), '/'));
        }

        return $urls;
    }

    public function test_paginated_listing_varies_description_per_page(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            foreach (range(1, 13) as $i) {
                Post::create([
                    'title' => "Post {$i}",
                    'code' => "post-{$i}",
                    'content' => 'content',
                    'published' => 1,
                    'category_id' => $category->id,
                ]);
            }
        });

        $first = $this->metaDescription($this->get(route('main.index'))->getContent());
        $second = $this->metaDescription($this->get(route('main.index').'?page=2')->getContent());

        $this->assertNotSame('', $first);
        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('страница 2', $second);
    }

    /**
     * Служебные страницы аккаунта в индексе не нужны, но метатег на них
     * всё равно обязан быть — их и увидел Вебмастер.
     */
    public function test_account_pages_are_noindex(): void
    {
        $this->get(route('login'))->assertSee('name="robots" content="noindex, follow"', false);
    }

    private function metaDescription(string $html): string
    {
        preg_match('/<meta name="description" content="([^"]*)"/', $html, $matches);

        return $matches[1] ?? '';
    }
}

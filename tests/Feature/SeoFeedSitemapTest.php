<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
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
     * HTML-карта сайта и листинги разделов перечисляли Category::all() и
     * Tag::all(), тогда как sitemap.xml фильтровал по published. Из-за этого
     * на сайте висели ссылки на разделы с нулём материалов: страница отдаёт
     * 200 с пустым списком, в XML-карту не попадает, а поисковик приходит на
     * неё по ссылке и записывает в малополезные. Тегов это касалось особенно:
     * их проставляет TagDetectorService при парсинге, задолго до публикации.
     */
    public function test_listings_hide_sections_without_published_posts(): void
    {
        Post::withoutSyncingToSearch(function () {
            $filledCategory = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $emptyCategory = Category::create(['title' => 'MySQL', 'code' => 'mysql']);
            $filledTag = Tag::create(['title' => 'Redis', 'code' => 'redis']);
            $emptyTag = Tag::create(['title' => 'Kubernetes', 'code' => 'kubernetes']);

            $published = Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $filledCategory->id,
            ]);
            $published->tags()->attach($filledTag);

            // Черновик раздел не «зажигает»: пока пост не опубликован, ссылок
            // на его категорию и тег быть не должно.
            $draft = Post::create([
                'title' => 'Draft post',
                'code' => 'draft-post',
                'content' => 'content',
                'published' => 0,
                'category_id' => $emptyCategory->id,
            ]);
            $draft->tags()->attach($emptyTag);
        });

        $expectations = [
            route('sitemap.index') => [
                'see' => [route('category.show', 'laravel'), route('tag.show', 'redis')],
                'dont' => [route('category.show', 'mysql'), route('tag.show', 'kubernetes')],
            ],
            route('category.index') => [
                'see' => [route('category.show', 'laravel')],
                'dont' => [route('category.show', 'mysql')],
            ],
            route('tag.index') => [
                'see' => [route('tag.show', 'redis')],
                'dont' => [route('tag.show', 'kubernetes')],
            ],
        ];

        foreach ($expectations as $url => $expected) {
            $response = $this->get($url);

            $response->assertOk();

            foreach ($expected['see'] as $link) {
                $response->assertSee($link, false);
            }

            foreach ($expected['dont'] as $link) {
                $response->assertDontSee($link, false);
            }
        }
    }

    /**
     * Ссылок на пустые разделы больше нет, но адреса, которые Вебмастер уже
     * успел проиндексировать, сами из выдачи не уйдут — закрываем их
     * метатегом. Именно noindex, а не 404: посты в разделе появятся при
     * следующей публикации, а отданная единожды 404 выбьет адрес насовсем.
     */
    public function test_section_without_published_posts_is_noindex(): void
    {
        Post::withoutSyncingToSearch(function () {
            $filledCategory = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            Category::create(['title' => 'MySQL', 'code' => 'mysql']);
            $filledTag = Tag::create(['title' => 'Redis', 'code' => 'redis']);
            Tag::create(['title' => 'Kubernetes', 'code' => 'kubernetes']);

            $published = Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $filledCategory->id,
            ]);
            $published->tags()->attach($filledTag);
        });

        $noindex = 'name="robots" content="noindex, follow"';

        $this->get(route('category.show', 'mysql'))->assertOk()->assertSee($noindex, false);
        $this->get(route('tag.show', 'kubernetes'))->assertOk()->assertSee($noindex, false);

        // Наполненный раздел закрывать нельзя — ради него всё и затевалось.
        $this->get(route('category.show', 'laravel'))->assertOk()->assertDontSee($noindex, false);
        $this->get(route('tag.show', 'redis'))->assertOk()->assertDontSee($noindex, false);

        // Страница за пределами пагинации — тоже пустой список, причём
        // канонический сам на себя. С проверкой по total() она оставалась
        // открытой для индексации, потому что посты в разделе-то есть.
        $this->get(route('category.show', 'laravel').'?page=99')->assertOk()->assertSee($noindex, false);
        $this->get(route('tag.show', 'redis').'?page=99')->assertOk()->assertSee($noindex, false);
    }

    /**
     * Новость и статья — один и тот же Post, но живут по разным адресам, и
     * Post\ShowController отдаёт 301 при обращении по «чужому». Значит,
     * ссылка на новость через route('post.show') — это ссылка в редирект.
     * Карты сайта это учитывали, а листинги, поиск, RSS и блок «похожие» —
     * нет, хотя новости попадают в них наравне со статьями (у новости есть и
     * категория, и теги — их проставляет парсер).
     */
    public function test_news_is_linked_by_its_own_url_everywhere(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $tag = Tag::create(['title' => 'Redis', 'code' => 'redis']);

            $news = Post::create([
                'title' => 'Published news',
                'code' => 'published-news',
                'content' => 'news content',
                'published' => 1,
                'is_news' => 1,
                'category_id' => $category->id,
            ]);
            $news->tags()->attach($tag);
        });

        foreach ([
            route('sitemap.index'),
            route('sitemap.xml'),
            route('feed.index'),
            route('category.show', 'laravel'),
            route('tag.show', 'redis'),
        ] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee(route('news.show', 'published-news'), false);
            $response->assertDontSee(route('post.show', 'published-news'), false);
        }
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

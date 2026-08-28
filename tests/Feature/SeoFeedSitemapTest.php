<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /**
     * RSS отдаёт метаданные канала (свой адрес, дата сборки, логотип) и
     * полный текст записи в content:encoded — в description остаётся анонс.
     */
    public function test_feed_exposes_channel_metadata_and_full_content(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                'content' => '<p>Полный текст записи.</p>',
                'published' => 1,
                'category_id' => $category->id,
            ]);
        });

        $response = $this->get(route('feed.index'));

        $response->assertOk();
        $response->assertSee('<atom:link href="'.route('feed.index').'" rel="self" type="application/rss+xml" />', false);
        $response->assertSee('<lastBuildDate>', false);
        $response->assertSee('<url>'.asset(config('seo.default_image')).'</url>', false);
        $response->assertSee('<content:encoded><![CDATA[<p>Полный текст записи.</p>]]></content:encoded>', false);
    }

    /**
     * sitemap.xml и feed.xml раньше рендерились на каждый запрос — при том
     * что перечитывают их в основном краулеры и читалки. Теперь выдача
     * кэшируется на час (инвалидация по TTL, см. Sitemap\XmlController) и
     * отдаёт Cache-Control с тем же часом.
     */
    public function test_sitemap_and_feed_are_cached_and_send_cache_headers(): void
    {
        Post::withoutSyncingToSearch(function () {
            Post::create([
                'title' => 'Первый пост',
                'code' => 'pervyj-post',
                'content' => 'content',
                'published' => 1,
            ]);
        });

        $response = $this->get(route('sitemap.xml'));

        $response->assertOk();
        $response->assertSee(route('post.show', 'pervyj-post'), false);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);

        // Пост, созданный после первого запроса, в кэшированную выдачу не
        // попадает — свежестью жертвуем осознанно, ради неё и TTL.
        Post::withoutSyncingToSearch(function () {
            Post::create([
                'title' => 'Второй пост',
                'code' => 'vtoroj-post',
                'content' => 'content',
                'published' => 1,
            ]);
        });

        $this->get(route('sitemap.xml'))
            ->assertOk()
            ->assertDontSee(route('post.show', 'vtoroj-post'), false);

        // После сброса кэша (это то, что сделает TTL) — появляется.
        Cache::flush();

        $this->get(route('sitemap.xml'))
            ->assertOk()
            ->assertSee(route('post.show', 'vtoroj-post'), false);

        $feed = $this->get(route('feed.index'));

        $feed->assertOk();
        $feed->assertSee('Второй пост');
        $this->assertStringContainsString('max-age=3600', (string) $feed->headers->get('Cache-Control'));
    }

    /**
     * Каноническая ссылка ленты новостей — текущая страница пагинации, как
     * на главной и в разделах: иначе все ?page=N схлопывались бы в первую.
     * Пустая страница закрывается noindex, а не 404 (см. News\IndexController).
     */
    public function test_news_listing_has_canonical_and_closes_empty_pages(): void
    {
        Post::withoutSyncingToSearch(function () {
            // 16 новостей, чтобы была вторая страница (PER_PAGE = 15).
            foreach (range(1, 16) as $i) {
                Post::create([
                    'title' => "Новость {$i}",
                    'code' => "novost-{$i}",
                    'content' => 'content',
                    'published' => 1,
                    'is_news' => 1,
                ]);
            }
        });

        $this->get(route('news.index'))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('news.index').'">', false)
            ->assertDontSee('name="robots" content="noindex, follow"', false);

        $this->get(route('news.index').'?page=2')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('news.index').'?page=2">', false)
            ->assertDontSee('name="robots" content="noindex, follow"', false);

        $this->get(route('news.index').'?page=99')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, follow"', false);
    }

    /**
     * Пагинация категории и тега раньше была без orderBy — порядок страниц
     * не гарантирован, и ?page=2 могла повторить посты с первой. Сортировка —
     * свежие первыми, как на главной и в /news.
     */
    public function test_category_and_tag_list_posts_newest_first(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $tag = Tag::create(['title' => 'Redis', 'code' => 'redis']);

            $old = Post::create([
                'title' => 'Старый пост',
                'code' => 'staryj-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
                'created_at' => '2026-08-01 10:00:00',
            ]);
            $old->tags()->attach($tag);

            $new = Post::create([
                'title' => 'Новый пост',
                'code' => 'novyj-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
                'created_at' => '2026-08-20 10:00:00',
            ]);
            $new->tags()->attach($tag);
        });

        foreach ([route('category.show', 'laravel'), route('tag.show', 'redis')] as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            $this->assertLessThan(
                mb_strpos($content, 'Старый пост'),
                mb_strpos($content, 'Новый пост'),
                "Листинг обязан отдавать свежие посты первыми: {$url}"
            );
        }
    }

    /**
     * Листинги размечаются CollectionPage с ItemList ссылок на посты — до
     * этого структурированных данных у лент не было вовсе. ItemList обязан
     * вести на посты их каноническими адресами: у новости это /news/{code}.
     */
    public function test_listings_expose_collection_page_schema(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $tag = Tag::create(['title' => 'Redis', 'code' => 'redis']);

            $post = Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            $post->tags()->attach($tag);

            Post::create([
                'title' => 'Published news',
                'code' => 'published-news',
                'content' => 'content',
                'published' => 1,
                'is_news' => 1,
                'category_id' => $category->id,
            ]);
        });

        foreach ([
            route('main.index'),
            route('category.show', 'laravel'),
            route('tag.show', 'redis'),
            route('news.index'),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('"@type":"CollectionPage"', false)
                ->assertSee('"@type":"ItemList"', false);
        }

        $this->get(route('main.index'))
            ->assertSee('"url":"'.route('post.show', 'published-post').'"', false);

        $this->get(route('news.index'))
            ->assertSee('"url":"'.route('news.show', 'published-news').'"', false);
    }

    /**
     * Новость размечается NewsArticle, а не общим BlogPosting: у новостного
     * типа свои требования к сниппету, а материал новостной по построению.
     */
    public function test_news_page_is_marked_up_as_news_article(): void
    {
        Post::withoutSyncingToSearch(function () {
            Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                'content' => 'content',
                'published' => 1,
            ]);
            Post::create([
                'title' => 'Published news',
                'code' => 'published-news',
                'content' => 'content',
                'published' => 1,
                'is_news' => 1,
            ]);
        });

        $this->get(route('news.show', 'published-news'))
            ->assertOk()
            ->assertSee('"@type":"NewsArticle"', false)
            ->assertDontSee('"@type":"BlogPosting"', false);

        $this->get(route('post.show', 'published-post'))
            ->assertOk()
            ->assertSee('"@type":"BlogPosting"', false)
            ->assertDontSee('"@type":"NewsArticle"', false);
    }

    /**
     * SearchAction указывал на /search?q=…, который Disallow в robots.txt, —
     * противоречивые сигналы поисковику (плюс Google отключил sitelinks
     * searchbox). Сама WebSite-схема остаётся.
     */
    public function test_website_schema_has_no_search_action(): void
    {
        $this->get(route('main.index'))
            ->assertOk()
            ->assertSee('"@type":"WebSite"', false)
            ->assertDontSee('SearchAction', false);
    }

    /**
     * wordCount в JSON-LD раньше считался str_word_count(), который не знает
     * кириллицу и на русском тексте отдавал единицы. Теперь это
     * Post::wordCount() — тот же подсчёт, что у времени чтения.
     */
    public function test_post_schema_word_count_counts_cyrillic(): void
    {
        Post::withoutSyncingToSearch(function () {
            Post::create([
                'title' => 'Published post',
                'code' => 'published-post',
                'content' => '<p>раз два три</p>',
                'published' => 1,
            ]);
        });

        $this->get(route('post.show', 'published-post'))
            ->assertOk()
            ->assertSee('"wordCount":3', false);
    }

    private function metaDescription(string $html): string
    {
        preg_match('/<meta name="description" content="([^"]*)"/', $html, $matches);

        return $matches[1] ?? '';
    }
}

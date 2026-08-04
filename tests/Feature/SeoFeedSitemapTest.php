<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * пустую строку. Проверяем каждый публичный тип страницы разом.
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
        });

        $urls = [
            route('main.index'),
            route('post.show', 'published-post'),
            route('category.index'),
            route('category.show', $category->code),
            route('tag.index'),
            route('sitemap.index'),
            route('main.search'),
            route('login'),
            route('register'),
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertNotSame('', $this->metaDescription($response->getContent()),
                "Пустой или отсутствующий meta description: {$url}");
        }
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

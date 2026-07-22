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
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Service\PostViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: post_views писала session_id в открытом виде
 * (это значение cookie laravel_session, то есть токен живой сессии) и несолёный
 * sha256 от IP — весь IPv4 перебирается за минуты. Дамп БД или доступ к
 * Telescope означал захват чужих сессий.
 */
class PostViewPseudonymizationTest extends TestCase
{
    use RefreshDatabase;

    private function createPost(): Post
    {
        return Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            return Post::create([
                'title' => 'Пост',
                'code' => 'post',
                'content' => 'content',
                'published' => true,
                'category_id' => $category->id,
            ]);
        });
    }

    public function test_view_stores_neither_raw_session_id_nor_plain_ip_hash(): void
    {
        $post = $this->createPost();
        $request = Request::create('/posts/post', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);

        app(PostViewService::class)->record($post, $request);

        $view = $post->views()->firstOrFail();

        $this->assertSame(hash_hmac('sha256', '203.0.113.7', (string) config('app.key')), $view->ip_hash);
        // Голый sha256 от IP — ровно то, что раньше лежало в колонке.
        $this->assertNotSame(hash('sha256', '203.0.113.7'), $view->ip_hash);
    }

    public function test_session_identifier_is_hashed_not_stored_verbatim(): void
    {
        $post = $this->createPost();

        $this->get(route('post.show', $post->code))->assertOk();

        $view = $post->views()->firstOrFail();
        $sessionId = session()->getId();

        $this->assertNotNull($view->session_hash);
        $this->assertNotSame($sessionId, $view->session_hash);
        $this->assertSame(hash_hmac('sha256', $sessionId, (string) config('app.key')), $view->session_hash);
    }

    public function test_dedup_within_window_still_works(): void
    {
        $post = $this->createPost();
        $request = Request::create('/posts/post', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);

        app(PostViewService::class)->record($post, $request);
        app(PostViewService::class)->record($post, $request);

        $this->assertSame(1, $post->views()->count());
    }
}

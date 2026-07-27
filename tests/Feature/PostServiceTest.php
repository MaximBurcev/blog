<?php

namespace Tests\Feature;

use App\DataTransferObjects\PostData;
use App\Events\PostCreated;
use App\Models\Category;
use App\Models\Post;
use App\Service\PostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * PostService не имел ни одного теста (test-audit-2026-07-24 C2), несмотря
 * на то что это единственный официальный путь создания/обновления Post
 * (транзакция, детект категории/тегов, идемпотентность по url).
 */
class PostServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_post_and_dispatches_event(): void
    {
        Event::fake([PostCreated::class]);

        $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

        Post::withoutSyncingToSearch(function () use ($category) {
            app(PostService::class)->store(PostData::fromArray([
                'title' => 'My New Post',
                'content' => '<p>content</p>',
                'category_id' => $category->id,
                'published' => true,
            ]));
        });

        $post = Post::where('code', 'my-new-post')->first();

        $this->assertNotNull($post);
        $this->assertSame('My New Post', $post->title);
        Event::assertDispatched(PostCreated::class, fn ($event) => $event->post->is($post));
    }

    /**
     * StorePostJob не идемпотентна сама по себе — при retry/redelivery
     * handle() выполняется заново целиком. store() использует
     * updateOrCreate по url как естественному ключу, чтобы повторный запуск
     * не плодил дубликаты постов.
     */
    public function test_store_is_idempotent_by_url(): void
    {
        $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

        Post::withoutSyncingToSearch(function () use ($category) {
            $service = app(PostService::class);

            $service->store(PostData::fromArray([
                'title' => 'Same Post',
                'content' => 'first run',
                'category_id' => $category->id,
                'url' => 'https://example.test/same-post',
            ]));

            $service->store(PostData::fromArray([
                'title' => 'Same Post Updated',
                'content' => 'second run (retry)',
                'category_id' => $category->id,
                'url' => 'https://example.test/same-post',
            ]));
        });

        $this->assertSame(1, Post::where('url', 'https://example.test/same-post')->count());
        $this->assertSame(
            'second run (retry)',
            Post::where('url', 'https://example.test/same-post')->first()->content
        );
    }

    public function test_store_without_url_creates_separate_posts(): void
    {
        $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

        Post::withoutSyncingToSearch(function () use ($category) {
            $service = app(PostService::class);

            $service->store(PostData::fromArray([
                'title' => 'Manual Post One',
                'content' => 'content',
                'category_id' => $category->id,
            ]));

            $service->store(PostData::fromArray([
                'title' => 'Manual Post Two',
                'content' => 'content',
                'category_id' => $category->id,
            ]));
        });

        $this->assertSame(2, Post::whereIn('code', ['manual-post-one', 'manual-post-two'])->count());
    }
}

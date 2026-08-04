<?php

namespace Tests\Feature;

use App\Events\PostLiked;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01 (IDOR): маршруты комментария и лайка
 * резолвят пост неявным биндингом по id, то есть мимо scopePublished, тогда
 * как Post\ShowController на неопубликованный отдаёт 404. Перебором id (он
 * автоинкрементный) можно было по коду ответа подтвердить существование ещё
 * не вышедшей статьи, оставить к ней комментарий и накрутить лайк до выхода.
 */
class DraftPostAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createPost(bool $published): Post
    {
        return Post::withoutSyncingToSearch(function () use ($published) {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            return Post::create([
                'title' => $published ? 'Опубликованный' : 'Черновик',
                'code' => $published ? 'published-post' : 'draft-post',
                'content' => 'content',
                'published' => $published,
                'category_id' => $category->id,
            ]);
        });
    }

    public function test_comment_to_draft_post_is_not_found(): void
    {
        $draft = $this->createPost(false);

        $response = $this->post(route('post.comment.store', $draft->id), [
            'name' => 'Гость',
            'message' => 'Комментарий к неопубликованной статье',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('comments', 0);
    }

    public function test_comment_to_published_post_still_works(): void
    {
        $post = $this->createPost(true);

        $response = $this->post(route('post.comment.store', $post->id), [
            'name' => 'Гость',
            'message' => 'Обычный комментарий',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('comments', 1);
    }

    public function test_like_of_draft_post_is_not_found(): void
    {
        $draft = $this->createPost(false);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/posts/'.$draft->id.'/like');

        $response->assertNotFound();
        $this->assertDatabaseCount('post_likes', 0);
    }

    public function test_like_of_published_post_still_works(): void
    {
        // Без подмены события broadcast() при QUEUE_CONNECTION=sync лезет в
        // Reverb прямо из запроса и роняет тест на отсутствующем сервере
        // (phpunit.xml не изолирует внешние сервисы — отдельная находка аудита).
        Event::fake([PostLiked::class]);

        $post = $this->createPost(true);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/posts/'.$post->id.'/like');

        $response->assertOk();
        $response->assertJson(['liked' => true, 'likes' => 1]);
        Event::assertDispatched(PostLiked::class);
    }

    /**
     * Канал post.{id} авторизовал любого аутентифицированного пользователя
     * заглушкой `=> true`, то есть подписка на канал несуществующего или
     * черновичного поста подтверждала факт его существования.
     */
    public function test_broadcast_channel_is_authorized_only_for_published_post(): void
    {
        $published = $this->createPost(true);
        $draft = $this->createPost(false);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-post.'.$published->id,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-post.'.$draft->id,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-post.'.($draft->id + 1000),
            ])
            ->assertForbidden();
    }
}

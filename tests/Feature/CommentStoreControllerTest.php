<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Комментарии: гостевой доступ + модерация + honeypot (см.
 * docs/comments-feature-status-2026-07-26.md). 'published' входит в
 * $fillable у Comment (нужно для админки), поэтому контроллер обязан сам
 * прибивать published=false, а не доверять полю из запроса — иначе гость
 * мог бы автоматически опубликовать свой комментарий, пропустив модерацию.
 */
class CommentStoreControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createPost(): Post
    {
        return Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            return Post::create([
                'title' => 'Test Post',
                'code' => 'test-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
        });
    }

    public function test_guest_comment_is_created_unpublished_regardless_of_request_input(): void
    {
        $post = $this->createPost();

        $response = $this->post(route('post.comment.store', $post), [
            'name' => 'Guest Attacker',
            'message' => 'Hello world',
            'published' => true,
        ]);

        $response->assertRedirect(route('post.show', $post->code).'#comments');

        $comment = Comment::firstOrFail();
        $this->assertFalse($comment->published);
        $this->assertNull($comment->user_id);
        $this->assertSame('Guest Attacker', $comment->guest_name);
    }

    public function test_authenticated_comment_uses_account_name_not_request_name(): void
    {
        $post = $this->createPost();
        $user = User::factory()->create(['name' => 'Real User']);

        $response = $this->actingAs($user)->post(route('post.comment.store', $post), [
            'name' => 'Spoofed Name',
            'message' => 'Hello world',
        ]);

        $response->assertRedirect(route('post.show', $post->code).'#comments');

        $comment = Comment::firstOrFail();
        $this->assertSame($user->id, $comment->user_id);
        $this->assertNull($comment->guest_name);
        $this->assertFalse($comment->published);
    }

    public function test_guest_without_name_is_rejected(): void
    {
        $post = $this->createPost();

        $response = $this->post(route('post.comment.store', $post), [
            'message' => 'Hello world',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Comment::count());
    }

    public function test_honeypot_field_silently_drops_comment(): void
    {
        $post = $this->createPost();

        $response = $this->post(route('post.comment.store', $post), [
            'name' => 'Bot',
            'message' => 'Buy cheap watches',
            'website' => 'https://spam.example',
        ]);

        // Тихий "успех" для бота — редирект как при обычной отправке,
        // но комментарий не создаётся.
        $response->assertRedirect(route('post.show', $post->code).'#comments');
        $this->assertSame(0, Comment::count());
    }

    public function test_unpublished_comment_is_not_shown_on_post_page(): void
    {
        $post = $this->createPost();
        $post->comments()->create([
            'guest_name' => 'Hidden Guest',
            'message' => 'Awaiting moderation',
            'published' => false,
        ]);

        $response = $this->get(route('post.show', $post->code));

        $response->assertOk();
        $response->assertDontSee('Awaiting moderation');
    }
}

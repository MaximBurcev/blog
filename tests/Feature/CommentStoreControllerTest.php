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

    private function createPost(string $code = 'test-post'): Post
    {
        return Post::withoutSyncingToSearch(function () use ($code) {
            $category = Category::create(['title' => 'Laravel '.$code, 'code' => 'laravel-'.$code]);

            return Post::create([
                'title' => 'Test Post',
                'code' => $code,
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

    /**
     * Ответ на комментарий: сохраняется с parent_id и уходит на модерацию
     * точно так же, как обычный комментарий (published=false).
     */
    public function test_reply_with_valid_parent_is_stored_unpublished(): void
    {
        $post = $this->createPost();
        $parent = $post->comments()->create([
            'guest_name' => 'Первый',
            'message' => 'Корневой комментарий',
            'published' => true,
        ]);

        $response = $this->post(route('post.comment.store', $post), [
            'name' => 'Гость',
            'message' => 'Ответ на комментарий',
            'parent_id' => $parent->id,
        ]);

        $response->assertRedirect(route('post.show', $post->code).'#comments');

        $reply = Comment::where('parent_id', $parent->id)->firstOrFail();
        $this->assertFalse($reply->published);
        $this->assertSame($post->id, $reply->post_id);
    }

    public function test_reply_to_comment_of_another_post_is_rejected(): void
    {
        $post = $this->createPost();
        $otherPost = $this->createPost('other-post');
        $foreign = $otherPost->comments()->create([
            'guest_name' => 'Первый',
            'message' => 'Комментарий чужого поста',
            'published' => true,
        ]);

        $response = $this->post(route('post.comment.store', $post), [
            'name' => 'Гость',
            'message' => 'Ответ',
            'parent_id' => $foreign->id,
        ]);

        $response->assertSessionHasErrors('parent_id');
        // Новый комментарий не создался — остался только чужой корневой.
        $this->assertSame(1, Comment::count());
    }

    public function test_reply_to_unpublished_comment_is_rejected(): void
    {
        $post = $this->createPost();
        $parent = $post->comments()->create([
            'guest_name' => 'Первый',
            'message' => 'Ещё на модерации',
            'published' => false,
        ]);

        $response = $this->post(route('post.comment.store', $post), [
            'name' => 'Гость',
            'message' => 'Ответ',
            'parent_id' => $parent->id,
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertSame(1, Comment::count());
    }

    /**
     * Вложенность одноуровневая: отвечать можно только на корневой
     * комментарий — страница поста глубже не рендерит.
     */
    public function test_reply_to_reply_is_rejected(): void
    {
        $post = $this->createPost();
        $root = $post->comments()->create([
            'guest_name' => 'Первый',
            'message' => 'Корневой комментарий',
            'published' => true,
        ]);
        $reply = $post->comments()->create([
            'guest_name' => 'Второй',
            'parent_id' => $root->id,
            'message' => 'Ответ',
            'published' => true,
        ]);

        $response = $this->post(route('post.comment.store', $post), [
            'name' => 'Гость',
            'message' => 'Ответ на ответ',
            'parent_id' => $reply->id,
        ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertSame(2, Comment::count());
    }
}

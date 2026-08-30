<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\CommentCreated;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Письмо о новом комментарии должно доезжать до всех, кто модерирует:
 * до 29.08.2026 его получал только админ, хотя модерация — зона редактора.
 */
class CommentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_and_editors_are_notified_but_readers_are_not(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $reader = User::factory()->create(['role' => UserRole::Reader]);

        $category = Category::create(['title' => 'PHP', 'code' => 'php']);
        $post = Post::withoutSyncingToSearch(fn () => Post::create([
            'title' => 'Статья',
            'code' => 'statya',
            'content' => '<p>Текст.</p>',
            'published' => 1,
            'category_id' => $category->id,
        ]));
        $comment = Comment::create([
            'post_id' => $post->id,
            'guest_name' => 'Гость',
            'message' => 'Комментарий на модерацию',
            'published' => false,
        ]);

        event(new CommentCreated($comment));

        Notification::assertSentTo($admin, CommentCreatedNotification::class);
        Notification::assertSentTo($editor, CommentCreatedNotification::class);
        Notification::assertNotSentTo($reader, CommentCreatedNotification::class);
    }
}

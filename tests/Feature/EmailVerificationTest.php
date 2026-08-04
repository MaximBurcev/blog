<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Регрессия на security-audit-2026-08-01 INF-5: MustVerifyEmail был
 * закомментирован, и аккаунт заводился на любой несуществующий адрес.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_verification_email(): void
    {
        Notification::fake();
        Event::fake([Registered::class]);

        $this->post('/register', [
            'name' => 'Новичок',
            'email' => 'newcomer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/');

        // Событие Registered → SendEmailVerificationNotification
        // (см. EventServiceProvider).
        Event::assertDispatched(Registered::class);

        $user = User::where('email', 'newcomer@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
    }

    public function test_verification_notification_is_actually_sent(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Новичок',
            'email' => 'newcomer2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        Notification::assertSentTo(
            User::where('email', 'newcomer2@example.com')->firstOrFail(),
            VerifyEmail::class
        );
    }

    public function test_unverified_user_cannot_like_post(): void
    {
        $post = $this->publishedPost();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post('/posts/'.$post->id.'/like')
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('post_likes', 0);
    }

    public function test_verified_user_can_like_post(): void
    {
        Event::fake([\App\Events\PostLiked::class]);

        $post = $this->publishedPost();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/posts/'.$post->id.'/like')
            ->assertOk();

        $this->assertDatabaseCount('post_likes', 1);
    }

    private function publishedPost(): Post
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
}

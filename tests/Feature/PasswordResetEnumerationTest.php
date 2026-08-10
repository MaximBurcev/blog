<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Регрессия на аудит 2026-08-09: форма сброса пароля работала оракулом
 * существования аккаунта (CWE-204) — для зарегистрированного адреса отдавала
 * «We have emailed your password reset link!», для чужого «We can't find a
 * user with that email address».
 */
class PasswordResetEnumerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // throttle:auth-sensitive — 5/мин на IP, а тесту нужно два запроса
        // подряд с одного адреса.
        RateLimiter::clear('auth-minute:127.0.0.1');
        RateLimiter::clear('auth-hour:127.0.0.1');
    }

    public function test_response_is_identical_for_known_and_unknown_email(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->post('/password/email', ['email' => 'known@example.com']);
        $unknown = $this->post('/password/email', ['email' => 'nobody@example.com']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());

        // Ключевое: у несуществующего адреса не должно быть ошибки на поле
        // email — именно она и выдавала отсутствие аккаунта.
        $unknown->assertSessionHasNoErrors();
        $known->assertSessionHasNoErrors();
        $this->assertSame(
            session()->get('status'),
            $unknown->getSession()->get('status')
        );
    }

    public function test_letter_is_sent_only_to_the_existing_address(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'known@example.com']);

        $this->post('/password/email', ['email' => 'nobody@example.com']);
        Notification::assertNothingSent();

        $this->post('/password/email', ['email' => 'known@example.com']);
        Notification::assertSentTo($user, ResetPassword::class);
    }
}

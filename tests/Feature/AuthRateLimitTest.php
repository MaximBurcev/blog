<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: Auth::routes() не вешает throttle ни на один
 * маршрут. POST /register был открыт без ограничений (спам-аккаунты, заодно
 * перебор занятых адресов по тексту ошибки валидации), а POST /password/email
 * ограничивался только брокером паролей — по ОДНОМУ адресу, не по IP, то есть
 * рассылка писем сброса на произвольные чужие ящики шла без лимита.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('auth-minute:127.0.0.1');
        RateLimiter::clear('auth-hour:127.0.0.1');
    }

    public function test_registration_is_rate_limited_by_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/register', [
                'name' => 'User '.$i,
                'email' => 'user'.$i.'@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertStatus(302);
        }

        $this->post('/register', [
            'name' => 'Шестой',
            'email' => 'sixth@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(429);

        $this->assertDatabaseMissing('users', ['email' => 'sixth@example.com']);
    }

    public function test_password_reset_request_is_rate_limited_by_ip(): void
    {
        Notification::fake();

        // Разные адреса: внутренний лимит брокера паролей (config/auth.php)
        // считает по email и такой сценарий не ловит вообще.
        for ($i = 1; $i <= 5; $i++) {
            User::factory()->create(['email' => 'victim'.$i.'@example.com']);

            $this->post('/password/email', ['email' => 'victim'.$i.'@example.com'])
                ->assertStatus(302);
        }

        User::factory()->create(['email' => 'victim6@example.com']);

        $this->post('/password/email', ['email' => 'victim6@example.com'])
            ->assertStatus(429);
    }

    public function test_registration_form_itself_is_not_throttled(): void
    {
        // Лимит висит только на POST: страницу регистрации люди перезагружают.
        for ($i = 0; $i < 8; $i++) {
            $this->get('/register')->assertOk();
        }
    }

    public function test_honeypot_field_silently_rejects_bot_registration(): void
    {
        $response = $this->post('/register', [
            'name' => 'Бот',
            'email' => 'bot@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'website' => 'http://spam.example',
        ]);

        // Ответ неотличим от успешного — скрипт не понимает, что отсеян.
        $response->assertStatus(302);
        $this->assertDatabaseMissing('users', ['email' => 'bot@example.com']);
        $this->assertGuest();
    }

    public function test_normal_registration_still_creates_user(): void
    {
        $this->post('/register', [
            'name' => 'Человек',
            'email' => 'human@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(302);

        $this->assertDatabaseHas('users', ['email' => 'human@example.com']);
        $this->assertAuthenticated();
    }
}

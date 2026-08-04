<?php

namespace Tests\Feature;

use Tests\Concerns\InteractsWithEnv;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01 (INF-6): config/session.php брал 'secure' из
 * env без дефолта, а ключа SESSION_SECURE_COOKIE не было и в .env.example.
 * Значение держалось только на ручной правке файла на сервере: любое
 * пересоздание окружения из шаблона (новый стенд, контейнер, переезд) снова
 * отдавало laravel_session и XSRF-TOKEN без флага Secure.
 */
class SessionCookieSecurityTest extends TestCase
{
    use InteractsWithEnv;

    private function sessionSecureFor(string $appEnv, ?string $explicit = null): mixed
    {
        return $this->withEnv(
            ['APP_ENV' => $appEnv, 'SESSION_SECURE_COOKIE' => $explicit],
            fn () => (require base_path('config/session.php'))['secure']
        );
    }

    public function test_production_defaults_to_secure_cookies(): void
    {
        $this->assertTrue($this->sessionSecureFor('production'));
    }

    public function test_local_does_not_force_secure_cookies(): void
    {
        // На http://laravel.local флаг Secure сделал бы сессию нерабочей.
        $this->assertFalse($this->sessionSecureFor('local'));
    }

    public function test_explicit_env_value_wins_over_default(): void
    {
        $this->assertFalse($this->sessionSecureFor('production', 'false'));
        $this->assertTrue($this->sessionSecureFor('local', 'true'));
    }
}

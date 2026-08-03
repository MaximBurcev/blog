<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: фикс MAJ-6 (Reverb принимал любой origin)
 * собирал allowed_origins из APP_URL целиком — со схемой и портом. Reverb же
 * сравнивает паттерн с ГОЛЫМ хостом (Server::verifyOrigin() →
 * parse_url($origin, PHP_URL_HOST) + Str::is), поэтому
 * 'http://laravel.local:8000' не совпадал с 'laravel.local' и сервер закрывал
 * ВСЕ соединения с InvalidOrigin — realtime не работал вообще.
 */
class ReverbAllowedOriginsTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function allowedOriginsFor(string $appUrl, ?string $explicitOrigins = null): array
    {
        $repository = Env::getRepository();

        $previousUrl = Env::get('APP_URL');
        $previousOrigins = Env::get('REVERB_ALLOWED_ORIGINS');

        $repository->set('APP_URL', $appUrl);
        $explicitOrigins === null
            ? $repository->clear('REVERB_ALLOWED_ORIGINS')
            : $repository->set('REVERB_ALLOWED_ORIGINS', $explicitOrigins);

        try {
            $config = require base_path('config/reverb.php');

            return $config['apps']['apps'][0]['allowed_origins'];
        } finally {
            $previousUrl === null ? $repository->clear('APP_URL') : $repository->set('APP_URL', $previousUrl);
            $previousOrigins === null
                ? $repository->clear('REVERB_ALLOWED_ORIGINS')
                : $repository->set('REVERB_ALLOWED_ORIGINS', $previousOrigins);
        }
    }

    /**
     * Повторяет проверку Reverb: vendor/laravel/reverb/src/Protocols/Pusher/Server.php
     * (verifyOrigin) сопоставляет паттерн с хостом, вырезанным из заголовка Origin.
     */
    private function originIsAccepted(string $origin, array $allowedOrigins): bool
    {
        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        foreach ($allowedOrigins as $allowedOrigin) {
            if (Str::is($allowedOrigin, $host)) {
                return true;
            }
        }

        return false;
    }

    public function test_default_origin_is_derived_from_app_url_host_without_scheme_and_port(): void
    {
        $origins = $this->allowedOriginsFor('http://laravel.local:8000');

        $this->assertSame(['laravel.local'], $origins);
    }

    public function test_browser_origin_of_own_site_is_accepted(): void
    {
        $origins = $this->allowedOriginsFor('http://laravel.local:8000');

        $this->assertTrue(
            $this->originIsAccepted('http://laravel.local:8000', $origins),
            'Reverb должен принимать соединение с собственного сайта'
        );
    }

    public function test_https_app_url_accepts_own_origin(): void
    {
        $origins = $this->allowedOriginsFor('https://blog.example.com');

        $this->assertSame(['blog.example.com'], $origins);
        $this->assertTrue($this->originIsAccepted('https://blog.example.com', $origins));
    }

    public function test_foreign_origin_is_rejected(): void
    {
        $origins = $this->allowedOriginsFor('https://blog.example.com');

        $this->assertFalse(
            $this->originIsAccepted('https://evil.com', $origins),
            'Cross-site WebSocket hijacking должен оставаться закрытым'
        );
        $this->assertFalse(
            $this->originIsAccepted('https://blog.example.com.evil.com', $origins),
            'Суффиксный обход домена должен оставаться закрытым'
        );
    }

    public function test_explicit_env_list_overrides_default_and_is_trimmed(): void
    {
        $origins = $this->allowedOriginsFor(
            'https://blog.example.com',
            'blog.example.com, admin.example.com'
        );

        $this->assertSame(['blog.example.com', 'admin.example.com'], $origins);
        $this->assertTrue($this->originIsAccepted('https://admin.example.com', $origins));
    }
}

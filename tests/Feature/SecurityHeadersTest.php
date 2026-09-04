<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Регрессия на security-audit-2026-07-24 MAJ-4: ни один security-заголовок
 * раньше не выставлялся вообще, а после первого фикса не хватало двух
 * главных — CSP и HSTS.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_response_has_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }

    /**
     * Директивы, которые реально что-то закрывают при текущей политике
     * (script-src вынужденно с 'unsafe-inline' — см. докблок middleware):
     * подгрузку скрипта с чужого хоста, увод формы наружу, подмену <base>,
     * object/embed и фрейминг страницы.
     */
    public function test_csp_locks_down_external_sources(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    /**
     * Внешние источники, без которых страницы ломаются: highlight.js с cdnjs
     * и его тема с jsdelivr на публичной части, шрифты в AdminLTE.
     */
    public function test_csp_allows_assets_the_pages_actually_use(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://cdnjs.cloudflare.com', $csp);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $csp);
        $this->assertStringContainsString('https://fonts.googleapis.com', $csp);
    }

    /**
     * Фронт держит постоянное соединение с Reverb (resources/js/bootstrap.js) —
     * под connect-src 'self' оно бы рвалось.
     */
    public function test_csp_allows_reverb_websocket(): void
    {
        Config::set('broadcasting.connections.reverb.options', [
            'host' => 'ws.example.test',
            'port' => 8081,
            'scheme' => 'https',
        ]);

        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/connect-src[^;]*wss:\/\/ws\.example\.test:8081/', $csp);
    }

    /**
     * Dev-сервер Vite отдаёт модули и HMR-сокет со своего порта: при
     * запущенном `npm run dev` его origin должен попадать в политику,
     * иначе локальная разработка встанет.
     */
    public function test_csp_allows_vite_dev_server_when_running_hot(): void
    {
        // Глобальный withoutVite() из TestCase подменяет Vite заглушкой, у
        // которой isRunningHot() всегда false, — а этот тест как раз про
        // поведение при живом dev-сервере. Возвращаем настоящий.
        $this->withVite();

        $hotFile = public_path('hot');
        $existed = file_exists($hotFile);

        try {
            file_put_contents($hotFile, 'http://localhost:5173');

            $csp = $this->get('/')->headers->get('Content-Security-Policy');

            $this->assertStringContainsString('http://localhost:5173', $csp);
            $this->assertStringContainsString('ws://localhost:5173', $csp);
        } finally {
            if (! $existed) {
                @unlink($hotFile);
            }
        }
    }

    /**
     * Превью картинки в Filament строится из blob: дважды: сам файл
     * заворачивается в Blob для <img>, а декодирует и рисует его Web Worker,
     * собранный тоже из blob:. Без первого источника поле показывало строку с
     * именем файла, без второго — пустую серую панель. Публичной части blob:
     * не нужен: воркеров там нет, а картинки приходят по http(s).
     */
    public function test_csp_allows_blob_images_and_workers_in_admin_panel_only(): void
    {
        $admin = $this->get('/'.config('admin.panel_path', 'filament').'/login')->headers->get('Content-Security-Policy');
        $public = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/img-src[^;]*blob:/', $admin);
        $this->assertMatchesRegularExpression('/worker-src[^;]*blob:/', $admin);

        $this->assertDoesNotMatchRegularExpression('/img-src[^;]*blob:/', $public);
        $this->assertStringNotContainsString('worker-src', $public);
    }

    /**
     * Режим обкатки перед ужесточением политики: браузер только репортит
     * нарушения, ничего не блокируя.
     */
    public function test_csp_can_be_switched_to_report_only(): void
    {
        Config::set('security.csp.report_only', true);

        $response = $this->get('/');

        $response->assertHeader('Content-Security-Policy-Report-Only');
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_csp_can_be_disabled(): void
    {
        Config::set('security.csp.enabled', false);

        $response = $this->get('/');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /**
     * На http Strict-Transport-Security браузером игнорируется — незачем
     * слать его на локальной разработке.
     */
    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        $this->assertNull($this->get('/')->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $response = $this->get('https://localhost/');

        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->assertStringContainsString(
            'upgrade-insecure-requests',
            $response->headers->get('Content-Security-Policy')
        );
    }

    /**
     * includeSubDomains/preload — opt-in: браузер запомнит политику на год,
     * и поддомен без сертификата станет недоступен.
     */
    public function test_hsts_subdomains_and_preload_are_opt_in(): void
    {
        Config::set('security.hsts.include_subdomains', true);
        Config::set('security.hsts.preload', true);

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
    }
}

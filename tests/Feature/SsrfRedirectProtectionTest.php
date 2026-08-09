<?php

namespace Tests\Feature;

use App\Jobs\StorePostJob;
use App\Service\ContentImageService;
use App\Support\UrlSafetyChecker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Интеграционная регрессия на SSRF per-hop redirect check (test-audit-2026-07-24
 * C3): UrlSafetyCheckerTest покрывает только резолвинг одного URL изолированно.
 * Настоящая защита — что КАЖДЫЙ хоп редиректа ревалидируется заново, а не
 * только исходный URL — до сих пор не была проверена end-to-end. Раньше
 * (до фикса) сервер шёл, куда его вёл 3xx-редирект чужой страницы, включая
 * localhost/внутреннюю сеть/метаданные облака.
 */
class SsrfRedirectProtectionTest extends TestCase
{
    public function test_content_image_service_refuses_to_follow_redirect_to_private_ip(): void
    {
        Storage::fake('public');

        Http::fake([
            'http://8.8.8.8/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/secret']),
        ]);

        $result = (new ContentImageService)->downloadImage('http://8.8.8.8/start');

        $this->assertNull($result);
        Http::assertSent(fn ($request) => str_contains($request->url(), '8.8.8.8'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_content_image_service_follows_redirect_between_public_hosts(): void
    {
        Storage::fake('public');

        Http::fake([
            'http://8.8.8.8/*' => Http::response('', 302, ['Location' => 'http://1.1.1.1/final.jpg']),
            'http://1.1.1.1/*' => Http::response($this->pngBytes(), 200),
        ]);

        $result = (new ContentImageService)->downloadImage('http://8.8.8.8/start');

        $this->assertNotNull($result);
        Storage::disk('public')->assertExists($result);
    }

    /**
     * StorePostJob::fetchHtml() шеллит curl напрямую (shell_exec), поэтому
     * его нельзя перехватить через Http::fake — но первая линия защиты
     * (resolvePublicIp до первого же хопа) должна отсекать небезопасный URL
     * без единого сетевого вызова, что и проверяем через reflection.
     */
    public function test_store_post_job_refuses_unsafe_url_before_any_fetch(): void
    {
        $job = new StorePostJob(['url' => 'http://169.254.169.254/latest/meta-data/']);

        $checkerProp = new ReflectionProperty(StorePostJob::class, 'urlSafetyChecker');
        $checkerProp->setAccessible(true);
        $checkerProp->setValue($job, new UrlSafetyChecker);

        $method = new ReflectionMethod(StorePostJob::class, 'fetchHtml');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($job));
    }
}

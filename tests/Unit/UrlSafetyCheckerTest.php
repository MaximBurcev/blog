<?php

namespace Tests\Unit;

use App\Support\UrlSafetyChecker;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SSRF-защита пайплайна скрейпинга (StorePostJob/ReleaseService/
 * ContentImageService): URL там не вводятся напрямую пользователем, а
 * извлекаются из чужого HTML (ссылки в дайджесте, og:image, src картинок),
 * поэтому сервер может быть уведён на localhost/внутреннюю сеть/метаданные
 * облака как через сам URL, так и через 3xx-редирект.
 */
class UrlSafetyCheckerTest extends TestCase
{
    public function test_rejects_non_http_scheme(): void
    {
        $checker = new UrlSafetyChecker;

        $this->assertFalse($checker->isSafe('file:///etc/passwd'));
        $this->assertFalse($checker->isSafe('ftp://example.com/file'));
        $this->assertFalse($checker->isSafe('gopher://127.0.0.1/'));
    }

    public function test_rejects_malformed_url(): void
    {
        $this->assertFalse((new UrlSafetyChecker)->isSafe('not a url'));
    }

    public function test_rejects_loopback_ip(): void
    {
        $this->assertFalse((new UrlSafetyChecker)->isSafe('http://127.0.0.1/admin'));
    }

    public function test_rejects_loopback_hostname(): void
    {
        $this->assertFalse((new UrlSafetyChecker)->isSafe('http://localhost/admin'));
    }

    public function test_rejects_private_network_ranges(): void
    {
        $checker = new UrlSafetyChecker;

        $this->assertFalse($checker->isSafe('http://10.0.0.1/'));
        $this->assertFalse($checker->isSafe('http://172.16.0.1/'));
        $this->assertFalse($checker->isSafe('http://192.168.1.1/'));
    }

    public function test_rejects_cloud_metadata_link_local_ip(): void
    {
        $this->assertFalse((new UrlSafetyChecker)->isSafe('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_accepts_public_ip(): void
    {
        $this->assertTrue((new UrlSafetyChecker)->isSafe('http://8.8.8.8/'));
    }

    public function test_rejects_blocked_domain(): void
    {
        $this->assertFalse((new UrlSafetyChecker)->isSafe('https://sub.facebook.com/some-post'));
    }

    public function test_allow_list_restricts_to_configured_domains(): void
    {
        Config::set('releases.allowed_domains', ['8.8.8.8']);

        $this->assertTrue((new UrlSafetyChecker)->isSafe('http://8.8.8.8/'));
        $this->assertFalse((new UrlSafetyChecker)->isSafe('http://1.1.1.1/'));
    }
}

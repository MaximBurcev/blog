<?php

namespace Tests\Unit;

use App\Support\UrlSafetyChecker;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SSRF-защита пайплайна скрейпинга (StorePostJob/ReleaseService/
 * ContentImageService): URL там не вводятся напрямую пользователем, а
 * извлекаются из чужого HTML (ссылки в дайджесте, og:image, src картинок),
 * поэтому сервер может быть уведён на localhost/внутреннюю сеть/метаданные
 * облака как через сам URL, так и через 3xx-редирект.
 *
 * resolvePublicIp() возвращает конкретный IP (для DNS-пиннинга в фетчере
 * через curl --resolve / CURLOPT_RESOLVE), а не просто bool — null означает
 * "небезопасно".
 */
class UrlSafetyCheckerTest extends TestCase
{
    public function test_rejects_non_http_scheme(): void
    {
        $checker = new UrlSafetyChecker;

        $this->assertNull($checker->resolvePublicIp('file:///etc/passwd'));
        $this->assertNull($checker->resolvePublicIp('ftp://example.com/file'));
        $this->assertNull($checker->resolvePublicIp('gopher://127.0.0.1/'));
    }

    public function test_rejects_malformed_url(): void
    {
        $this->assertNull((new UrlSafetyChecker)->resolvePublicIp('not a url'));
    }

    public function test_rejects_loopback_ip(): void
    {
        $this->assertNull((new UrlSafetyChecker)->resolvePublicIp('http://127.0.0.1/admin'));
    }

    public function test_rejects_loopback_hostname(): void
    {
        $this->assertNull((new UrlSafetyChecker)->resolvePublicIp('http://localhost/admin'));
    }

    public function test_rejects_private_network_ranges(): void
    {
        $checker = new UrlSafetyChecker;

        $this->assertNull($checker->resolvePublicIp('http://10.0.0.1/'));
        $this->assertNull($checker->resolvePublicIp('http://172.16.0.1/'));
        $this->assertNull($checker->resolvePublicIp('http://192.168.1.1/'));
    }

    public function test_rejects_cloud_metadata_link_local_ip(): void
    {
        $this->assertNull((new UrlSafetyChecker)->resolvePublicIp('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_accepts_public_ip_and_returns_it(): void
    {
        $this->assertSame('8.8.8.8', (new UrlSafetyChecker)->resolvePublicIp('http://8.8.8.8/'));
    }

    /**
     * filter_var(FILTER_FLAG_NO_PRIV_RANGE) не разворачивает IPv4-mapped
     * IPv6-литералы во вложенный IPv4 перед проверкой диапазона, поэтому
     * '::ffff:169.254.169.254' раньше проходил как "публичный" — прямой
     * путь к cloud metadata/loopback в обход всей защиты, без DNS-rebinding.
     */
    public function test_rejects_ipv4_mapped_ipv6_cloud_metadata(): void
    {
        $checker = new UrlSafetyChecker;

        $this->assertNull($checker->resolvePublicIp('http://[::ffff:169.254.169.254]/latest/meta-data/'));
        $this->assertNull($checker->resolvePublicIp('http://[::ffff:127.0.0.1]/'));
        $this->assertNull($checker->resolvePublicIp('http://[::ffff:10.0.0.1]/'));
    }

    public function test_rejects_blocked_domain(): void
    {
        $this->assertNull((new UrlSafetyChecker)->resolvePublicIp('https://sub.facebook.com/some-post'));
    }

    public function test_allow_list_restricts_to_configured_domains(): void
    {
        Config::set('releases.allowed_domains', ['8.8.8.8']);

        $this->assertSame('8.8.8.8', (new UrlSafetyChecker)->resolvePublicIp('http://8.8.8.8/'));
        $this->assertNull((new UrlSafetyChecker)->resolvePublicIp('http://1.1.1.1/'));
    }

    /**
     * Раньше домены сравнивались через str_contains($host, $domain) —
     * это пропускало allow-listed 'medium.com' для 'medium.com.attacker.test'
     * (over-permissive: чужой домен проходит allow-list) и блокировало
     * несвязанный 'notfacebook.com' по blocklist 'facebook.com' (ложное
     * срабатывание). Логика сравнения приватная — проверяем через reflection,
     * чтобы не зависеть от реального DNS.
     */
    public function test_domain_match_accepts_exact_host_and_real_subdomain(): void
    {
        $this->assertTrue($this->hostMatchesDomain('facebook.com', 'facebook.com'));
        $this->assertTrue($this->hostMatchesDomain('sub.facebook.com', 'facebook.com'));
    }

    public function test_domain_match_rejects_lookalike_suffix_and_prefix(): void
    {
        $this->assertFalse($this->hostMatchesDomain('facebook.com.evil.test', 'facebook.com'));
        $this->assertFalse($this->hostMatchesDomain('notfacebook.com', 'facebook.com'));
    }

    private function hostMatchesDomain(string $host, string $domain): bool
    {
        $method = new ReflectionMethod(UrlSafetyChecker::class, 'hostMatchesDomain');
        $method->setAccessible(true);

        return $method->invoke(new UrlSafetyChecker, $host, $domain);
    }
}

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

    /**
     * Trailing dot — валидный синтаксис FQDN-корня, DNS резолвит его как тот
     * же домен, но старое строковое сравнение в hostMatchesDomain() не видело
     * 'facebook.com.' как совпадение с blocked_domains 'facebook.com'.
     */
    public function test_rejects_blocked_domain_with_trailing_dot(): void
    {
        $this->assertNull((new UrlSafetyChecker)->resolvePublicIp('https://sub.facebook.com./some-post'));
    }

    /**
     * CGNAT (RFC 6598, 100.64.0.0/10) не входит в диапазоны, которые
     * FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE считает приватными — но на
     * 100.100.100.200 отдаются метаданные инстансов Alibaba Cloud.
     */
    public function test_rejects_cgnat_range(): void
    {
        $checker = new UrlSafetyChecker;

        $this->assertNull($checker->resolvePublicIp('http://100.64.0.1/'));
        $this->assertNull($checker->resolvePublicIp('http://100.100.100.200/latest/meta-data/'));
        $this->assertNull($checker->resolvePublicIp('http://100.127.255.254/'));
    }

    public function test_accepts_ip_just_outside_cgnat_range(): void
    {
        // 100.63.255.255 и 100.128.0.0 формально вне 100.64.0.0/10
        $checker = new UrlSafetyChecker;

        $this->assertSame('100.63.255.255', $checker->resolvePublicIp('http://100.63.255.255/'));
        $this->assertSame('100.128.0.0', $checker->resolvePublicIp('http://100.128.0.0/'));
    }

    public function test_rejects_ietf_protocol_assignments_and_6to4_relay_and_nat64(): void
    {
        $checker = new UrlSafetyChecker;

        $this->assertNull($checker->resolvePublicIp('http://192.0.0.1/'));
        $this->assertNull($checker->resolvePublicIp('http://192.88.99.1/'));
        $this->assertNull($checker->resolvePublicIp('http://198.18.0.1/'));
        // 64:ff9b::808:808 — NAT64-представление публичного 8.8.8.8, но
        // сам well-known prefix должен блокироваться независимо от того,
        // что "внутри" — это буквально прыжок в другую (embedded) сеть.
        $this->assertNull($checker->resolvePublicIp('http://[64:ff9b::808:808]/'));
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

    public function test_normalize_host_strips_trailing_dot_and_lowercases(): void
    {
        $this->assertSame('facebook.com', $this->normalizeHost('Facebook.COM.'));
        $this->assertSame('facebook.com', $this->normalizeHost('facebook.com'));
    }

    public function test_normalize_host_converts_idn_to_ascii(): void
    {
        // яндекс.рф -> punycode, чтобы одно и то же имя в разных
        // представлениях сравнивалось с blocked/allowed_domains одинаково
        $this->assertSame('xn--d1acpjx3f.xn--p1ai', $this->normalizeHost('яндекс.рф'));
    }

    private function normalizeHost(string $host): string
    {
        $method = new ReflectionMethod(UrlSafetyChecker::class, 'normalizeHost');
        $method->setAccessible(true);

        return $method->invoke(new UrlSafetyChecker, $host);
    }

    /**
     * parse_url отдаёт IPv6-литерал в скобках ('[::1]'), поэтому без их
     * снятия адрес не распознавался как IP: проверка диапазонов к нему не
     * применялась вообще, и отклонялся он лишь побочно — тем, что DNS-запрос
     * по такому «имени» не резолвится.
     */
    public function test_normalize_host_strips_ipv6_brackets(): void
    {
        $this->assertSame('::1', $this->normalizeHost('[::1]'));
        $this->assertSame('2001:db8::1', $this->normalizeHost('[2001:DB8::1]'));
    }

    /**
     * Пиннинг работает только если HTTP-клиент разберёт ровно тот хост,
     * который проверялся и попал в --resolve. parse_url и libcurl нормализуют
     * по-разному (trailing dot, IDN, регистр) — при расхождении запись
     * пиннинга молча не матчится и curl делает собственный live-резолв,
     * возвращая окно rebinding/TOCTOU (MAJ-2). Поэтому URL отдаётся фетчеру
     * уже с нормализованным хостом.
     */
    public function test_canonical_url_substitutes_normalized_host(): void
    {
        $this->assertSame(
            'http://medium.com/p?q=1#x',
            $this->canonicalUrl('http://MEDIUM.COM./p?q=1#x', 'MEDIUM.COM.', 'medium.com', false)
        );
        $this->assertSame(
            'https://xn--d1acpjx3f.xn--p1ai:8443/a',
            $this->canonicalUrl('https://яндекс.рф:8443/a', 'яндекс.рф', 'xn--d1acpjx3f.xn--p1ai', false)
        );
    }

    /**
     * У IP-литерала пиннинг не нужен (DNS не участвует), а пересборка URL из
     * IPv6 ломалась бы на скобках — URL остаётся как есть.
     */
    public function test_canonical_url_leaves_ip_literal_untouched(): void
    {
        $this->assertSame(
            'http://[2001:db8::1]:8080/x',
            $this->canonicalUrl('http://[2001:db8::1]:8080/x', '[2001:db8::1]', '2001:db8::1', true)
        );
    }

    private function canonicalUrl(string $url, string $rawHost, string $host, bool $isIpLiteral): ?string
    {
        $method = new ReflectionMethod(UrlSafetyChecker::class, 'canonicalUrl');
        $method->setAccessible(true);

        return $method->invoke(new UrlSafetyChecker, $url, $rawHost, $host, $isIpLiteral);
    }

    public function test_resolve_target_marks_ip_literal_host(): void
    {
        $target = (new UrlSafetyChecker)->resolveTarget('http://8.8.8.8/x');

        $this->assertNotNull($target);
        $this->assertTrue($target->hostIsIpLiteral);
        $this->assertNull($target->resolveEntry());
        $this->assertSame('8.8.8.8', $target->ip);
    }

    public function test_resolve_target_returns_null_for_unsafe_url(): void
    {
        $this->assertNull((new UrlSafetyChecker)->resolveTarget('http://127.0.0.1/admin'));
    }
}

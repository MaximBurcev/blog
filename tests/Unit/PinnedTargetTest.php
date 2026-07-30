<?php

namespace Tests\Unit;

use App\Support\PinnedTarget;
use Tests\TestCase;

/**
 * Пиннинг IP держится на двух вещах, которые проверяются здесь:
 * запись для --resolve/CURLOPT_RESOLVE и сверка фактического адреса
 * соединения (security-audit-2026-07-24 MAJ-2 — раньше сверки не было
 * вообще, и незамеченный промах пиннинга означал молчаливый живой DNS-резолв
 * силами curl, то есть открытое окно rebinding/TOCTOU).
 */
class PinnedTargetTest extends TestCase
{
    public function test_resolve_entry_pins_host_port_to_ip(): void
    {
        $target = new PinnedTarget('https://example.com/a', 'example.com', 443, '93.184.216.34', false);

        $this->assertSame('example.com:443:93.184.216.34', $target->resolveEntry());
    }

    /**
     * У IP-литерала DNS не участвует — подменять между проверкой и
     * соединением нечего, запись пиннинга не нужна.
     */
    public function test_resolve_entry_is_null_for_ip_literal_host(): void
    {
        $target = new PinnedTarget('http://8.8.8.8/', '8.8.8.8', 80, '8.8.8.8', true);

        $this->assertNull($target->resolveEntry());
    }

    public function test_ip_matches_exact_address(): void
    {
        $target = new PinnedTarget('http://example.com/', 'example.com', 80, '8.8.8.8', false);

        $this->assertTrue($target->ipMatches('8.8.8.8'));
        $this->assertFalse($target->ipMatches('8.8.4.4'));
    }

    /**
     * Один и тот же адрес приходит от curl в разной записи — сравнение
     * должно быть по значению, иначе получим ложную тревогу на каждом
     * запросе через dual-stack сокет или к IPv6-хосту.
     */
    public function test_ip_matches_across_equivalent_representations(): void
    {
        $ipv4 = new PinnedTarget('http://example.com/', 'example.com', 80, '8.8.8.8', false);
        $this->assertTrue($ipv4->ipMatches('::ffff:8.8.8.8'));
        $this->assertTrue($ipv4->ipMatches('[::ffff:8.8.8.8]'));

        $ipv6 = new PinnedTarget('http://example.com/', 'example.com', 80, '2001:db8::1', false);
        $this->assertTrue($ipv6->ipMatches('2001:0db8:0000:0000:0000:0000:0000:0001'));
        $this->assertFalse($ipv6->ipMatches('2001:db8::2'));
    }

    public function test_ip_matches_rejects_garbage_instead_of_passing_it(): void
    {
        $target = new PinnedTarget('http://example.com/', 'example.com', 80, '8.8.8.8', false);

        $this->assertFalse($target->ipMatches(''));
        $this->assertFalse($target->ipMatches('not-an-ip'));
    }
}

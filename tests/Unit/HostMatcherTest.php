<?php

namespace Tests\Unit;

use App\Support\HostMatcher;
use Tests\TestCase;

/**
 * Регрессия на аудит 2026-08-09: выбор селектора в трёх местах
 * (ContentSelectorResolver, ReleaseService, SiteSelector) сравнивал хост с
 * доменом правила через str_contains — то есть 'medium.com.evil.tld'
 * подбирал правило доверенного 'medium.com'.
 */
class HostMatcherTest extends TestCase
{
    public static function matchingHosts(): array
    {
        return [
            'точное совпадение' => ['dev.to', 'dev.to'],
            'поддомен' => ['www.dev.to', 'dev.to'],
            'глубокий поддомен' => ['blog.jetbrains.com', 'jetbrains.com'],
            'регистр не важен' => ['WWW.Dev.To', 'dev.to'],
            'точка в конце хоста' => ['www.dev.to.', 'dev.to'],
            'точка в начале правила' => ['www.dev.to', '.dev.to'],
        ];
    }

    /**
     * @dataProvider matchingHosts
     */
    public function test_matches_host_and_its_subdomains(string $host, string $domain): void
    {
        $this->assertTrue(HostMatcher::matches($host, $domain));
    }

    public static function nonMatchingHosts(): array
    {
        return [
            'домен как префикс чужого' => ['medium.com.evil.tld', 'medium.com'],
            'домен как подстрока без границы' => ['notdev.to', 'dev.to'],
            'суффикс без точки' => ['xdev.to', 'dev.to'],
            'другой домен' => ['example.com', 'dev.to'],
            'пустой хост' => ['', 'dev.to'],
            'пустое правило' => ['dev.to', ''],
        ];
    }

    /**
     * @dataProvider nonMatchingHosts
     */
    public function test_does_not_match_lookalike_hosts(string $host, string $domain): void
    {
        $this->assertFalse(HostMatcher::matches($host, $domain));
    }
}

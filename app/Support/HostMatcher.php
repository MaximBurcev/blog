<?php

namespace App\Support;

/**
 * Сопоставление хоста с доменом правила по границе метки, а не по подстроке.
 *
 * `str_contains($host, $domain)` подбирал правило 'medium.com' для
 * 'medium.com.evil.tld': достаточно завести поддомен с нужным префиксом,
 * чтобы чужой сайт разбирался селектором доверенного источника.
 * UrlSafetyChecker эту ошибку у себя уже исправил (там она давала обход
 * блок-листа), но выбор селектора в трёх других местах остался на наивном
 * варианте — класс сводит все четыре к одной реализации.
 */
final class HostMatcher
{
    /**
     * Точное совпадение либо поддомен с точкой-границей.
     */
    public static function matches(string $host, string $domain): bool
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $domain = strtolower(rtrim(ltrim(trim($domain), '.'), '.'));

        if ($host === '' || $domain === '') {
            return false;
        }

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /**
     * Правило из карты «домен => значение» для хоста статьи или страницы
     * дайджеста. null, если ни одно не подошло.
     *
     * Под один хост может подойти несколько правил (домен матчится по
     * границе метки), поэтому берём самое специфичное — самый длинный домен.
     * Ровно так уже поступает SiteSelector::selectorForHost(), а вот копии
     * этого цикла в конфиге брали ПЕРВОЕ совпадение: с ростом карты
     * domain_selectors до 27 записей достаточно завести рядом 'mozilla.org',
     * чтобы статьи с 'hacks.mozilla.org' начали разбираться чужим правилом
     * и превращаться в заглушки.
     *
     * Метод принимает хост, а не URL: у вызывающих он уже разобран, а
     * повторный parse_url() внутри разъезжался бы с их обработкой битого
     * адреса (одни писали `?? ''`, другие `(string)`).
     *
     * @param  array<string, mixed>  $map
     */
    public static function lookup(string $host, array $map): mixed
    {
        $domains = array_keys($map);
        usort($domains, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

        foreach ($domains as $domain) {
            if (self::matches($host, (string) $domain)) {
                return $map[$domain];
            }
        }

        return null;
    }
}

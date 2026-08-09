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
}

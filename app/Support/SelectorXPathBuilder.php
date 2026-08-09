<?php

namespace App\Support;

/**
 * Переводит CSS/ID-подобный селектор (#id, .class, tag, или голое имя
 * класса без точки) в XPath-запрос для поиска контентных узлов.
 *
 * selector задаётся вручную в админке при добавлении поста/релиза, поэтому
 * значение экранируется через literal(), а не подставляется в запрос
 * напрямую: иначе `'] | //script | //*[@id='` сломало бы структуру
 * XPath-выражения и расширило выборку за пределы контентного блока
 * (XPath injection, CWE-91).
 *
 * Класс общий для StorePostJob и TranslateService: раньше защищённый
 * вариант жил только в джобе, а в сервисе перевода рядом оставалась
 * прямая конкатенация — ловушка на регрессию.
 */
final class SelectorXPathBuilder
{
    public static function build(string $selector): string
    {
        if (str_starts_with($selector, '#')) {
            return '//*[@id='.self::literal(ltrim($selector, '#')).']';
        }

        if (str_starts_with($selector, '.')) {
            return self::classQuery(ltrim($selector, '.'));
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $selector)) {
            return "(//{$selector})[1]";
        }

        return self::classQuery($selector);
    }

    /**
     * Строит безопасный XPath string-литерал из произвольной строки. XPath
     * 1.0 не умеет экранировать кавычку внутри строки того же типа — если
     * значение содержит и одинарные, и двойные кавычки, единственный
     * стандартный способ — склеить через concat().
     */
    public static function literal(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        if (! str_contains($value, '"')) {
            return "\"{$value}\"";
        }

        $parts = array_map(fn ($part) => "'{$part}'", explode("'", $value));

        return 'concat('.implode(', "\'", ', $parts).')';
    }

    private static function classQuery(string $class): string
    {
        return "//*[contains(concat(' ', normalize-space(@class), ' '), concat(' ', ".self::literal($class).", ' '))]";
    }
}

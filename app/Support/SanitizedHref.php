<?php

namespace App\Support;

/**
 * Приводит href со страницы стороннего дайджеста к безопасному виду.
 *
 * Ссылки приходят из чужого HTML и оседают в базе (posts.url, news.url), а
 * потом рендерятся кликабельными — в панели, где CSP намеренно ослаблена до
 * 'unsafe-inline', `javascript:` был бы рабочим. Экранирование Blade тут не
 * спасает: оно защищает от выхода из атрибута, но не от схемы.
 */
final class SanitizedHref
{
    /**
     * Возвращает href либо null, если схему пускать нельзя.
     */
    public static function fromString(?string $href): ?string
    {
        $href = trim((string) $href);

        if ($href === '') {
            return null;
        }

        // Управляющие символы вырезаем ДО разбора: браузер по WHATWG URL
        // выкидывает ASCII tab/newline из адреса, а parse_url на них
        // спотыкается и не распознаёт схему. То есть "jav\tascript:alert(1)"
        // проходил проверку как «относительная ссылка», а в браузере
        // оставался рабочим javascript:-URI.
        $href = (string) preg_replace('/[\x00-\x20\x7F]/', '', $href);

        if ($href === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // Схема не распозналась, но двоеточие стоит до первого слэша —
        // значит это всё-таки схема, просто нестандартная. Не пропускаем.
        if ($scheme === '' && preg_match('#^[^/?\#]*:#', $href) === 1) {
            return null;
        }

        return $href;
    }
}

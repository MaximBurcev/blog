<?php

namespace App\Support;

/**
 * Достаёт статью из RSS-фида источника — обходной путь для площадок,
 * закрывших страницы статей антибот-проверкой.
 *
 * Medium с августа 2026 отдаёт на страницы статей Cloudflare managed
 * challenge (HTTP 403, «Just a moment...»), который требует исполнения JS
 * и потому недостижим для curl. При этом RSS отдаётся без проверки и
 * содержит полный текст в content:encoded — проверено на статье, которую
 * пайплайн не смог забрать напрямую: 5722 символа, без обрезки.
 *
 * Класс намеренно без ввода-вывода: получает уже скачанный XML и отдаёт
 * разобранные поля. Скачиванием занимается StorePostJob — там уже есть
 * SSRF-проверка с IP-пиннингом, и заводить рядом вторую не нужно.
 */
final class FeedArticleLocator
{
    /**
     * Маркеры урезанного контента: у платных member-only статей в фид
     * попадает только вступление со ссылкой «читать дальше».
     */
    private const TRUNCATION_MARKERS = [
        'Continue reading on Medium',
        'medium.com/m/signin',
        'This story is only available',
    ];

    /**
     * Адрес фида по адресу статьи.
     *
     * medium.com/@автор/slug-id      → medium.com/feed/@автор
     * medium.com/публикация/slug-id  → medium.com/feed/публикация
     * blog.example.com/slug-id       → blog.example.com/feed
     *
     * Третье правило — общее: у площадок на движке Medium (и у большинства
     * блогов вообще) фид лежит на /feed. Пробовать его дёшево, потому что
     * вызывается это только после отказа основного пути.
     */
    public static function feedUrlFor(string $articleUrl): ?string
    {
        $parts = parse_url($articleUrl);

        if (! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        $segments = array_values(array_filter(explode('/', trim($parts['path'] ?? '', '/'))));
        $host = strtolower(rtrim($parts['host'], '.'));

        // Раздел автора/публикации в пути есть только на самом medium.com.
        // У поддоменов (ecnmee.medium.com/slug-id) автор закодирован в хосте,
        // и путь состоит из одного сегмента — по общему правилу ниже фид
        // лежит на /feed. Раньше сюда попадала ветка medium.com, сегментов
        // было меньше двух, и функция возвращала null: для таких статей RSS
        // не пробовался вовсе.
        if (in_array($host, ['medium.com', 'www.medium.com'], true)) {
            // На medium.com/feed уже фид, а не статья.
            if (count($segments) < 2) {
                return null;
            }

            return $origin.'/feed/'.$segments[0];
        }

        return $origin.'/feed';
    }

    /**
     * Ищет в фиде статью по её адресу.
     *
     * @return array{title: string, html: string, published_at: ?string}|null
     */
    public static function findArticle(string $feedXml, string $articleUrl): ?array
    {
        $items = self::parseItems($feedXml);

        if ($items === []) {
            return null;
        }

        $wantedPath = self::normalizePath($articleUrl);
        $wantedId = self::articleId($articleUrl);

        foreach ($items as $item) {
            $link = trim((string) ($item->link ?? ''));

            if ($link === '') {
                continue;
            }

            $matches = self::normalizePath($link) === $wantedPath
                || ($wantedId !== null && str_contains($link, $wantedId));

            if (! $matches) {
                continue;
            }

            // content:encoded лежит в отдельном пространстве имён.
            $html = trim((string) ($item->children('content', true)->encoded ?? ''));
            $title = trim(html_entity_decode((string) ($item->title ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($html === '' || $title === '') {
                return null;
            }

            return [
                'title' => $title,
                'html' => $html,
                'published_at' => trim((string) ($item->pubDate ?? '')) ?: null,
            ];
        }

        return null;
    }

    public static function isTruncated(string $html): bool
    {
        foreach (self::TRUNCATION_MARKERS as $marker) {
            if (stripos($html, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, \SimpleXMLElement>
     */
    private static function parseItems(string $feedXml): array
    {
        if (trim($feedXml) === '') {
            return [];
        }

        // LIBXML_NONET запрещает сетевые обращения при разборе, а внешние
        // сущности в PHP 8 не подгружаются по умолчанию — XXE через чужой
        // фид закрыт с обеих сторон. NOCDATA нужен, потому что Medium
        // заворачивает и заголовок, и тело в CDATA.
        $xml = @simplexml_load_string($feedXml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if ($xml === false) {
            return [];
        }

        $items = $xml->channel->item ?? $xml->item ?? null;

        return $items === null ? [] : iterator_to_array($items, false);
    }

    /**
     * Хост + путь без схемы, query, якоря и хвостового слэша — чтобы
     * ссылка из фида сопоставлялась с адресом из дайджеста, даже если у
     * той приписан ?source=rss или utm-метки.
     */
    private static function normalizePath(string $url): string
    {
        $parts = parse_url($url);

        return strtolower(($parts['host'] ?? '').'/'.trim($parts['path'] ?? '', '/'));
    }

    /**
     * Идентификатор статьи Medium — hex-хвост последнего сегмента пути
     * (…-1ca68f019664). Запасной способ сопоставления: у ссылки из фида
     * может отличаться слаг, но id остаётся тем же.
     */
    private static function articleId(string $url): ?string
    {
        $segments = array_values(array_filter(explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'))));
        $last = end($segments);

        if ($last === false) {
            return null;
        }

        return preg_match('/-([0-9a-f]{8,})$/i', $last, $m) ? $m[1] : null;
    }
}

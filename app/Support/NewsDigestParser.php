<?php

namespace App\Support;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Достаёт новости из секции дайджеста PHP Weekly.
 *
 * Вёрстка секции — плоская, без обёрток на элемент:
 *
 *   <td class="bodyContent">
 *     <h2>News and Announcements</h2><br>
 *     <a href="URL">Заголовок</a><br>
 *     Текст описания
 *     <br><br>
 *     <a href="URL">Следующий заголовок</a><br>
 *     ...
 *
 * То есть описание — это текстовые узлы между ссылкой и следующей ссылкой,
 * а не отдельный элемент. Поэтому идём по узлам, а не по селектору.
 *
 * Класс без ввода-вывода: получает уже скачанный HTML. Скачиванием занимается
 * ReleaseService — там SSRF-проверка с IP-пиннингом, и заводить рядом вторую
 * не нужно.
 */
final class NewsDigestParser
{
    /**
     * Описания короче этого считаем мусором (обрывки разметки, «read more»).
     */
    private const MIN_SUMMARY_LENGTH = 40;

    /**
     * @return array<int, array{title: string, url: string, summary: string}>
     */
    public static function parse(string $html, string $sectionHeading): array
    {
        if (trim($html) === '') {
            return [];
        }

        $section = self::findSection($html, $sectionHeading);

        if ($section === null) {
            return [];
        }

        return self::extractItems($section);
    }

    private static function findSection(string $html, string $heading): ?\DOMNode
    {
        $crawler = new Crawler($html);
        $found = null;

        $crawler->filter('td.bodyContent')->each(function (Crawler $td) use ($heading, &$found) {
            if ($found !== null) {
                return;
            }

            $h2 = $td->filter('h2');

            if ($h2->count() === 0) {
                return;
            }

            if (self::normalize($h2->first()->text()) === self::normalize($heading)) {
                $found = $td->getNode(0);
            }
        });

        return $found;
    }

    /**
     * @return array<int, array{title: string, url: string, summary: string}>
     */
    private static function extractItems(\DOMNode $section): array
    {
        $items = [];
        $current = null;

        foreach ($section->childNodes as $node) {
            if ($node instanceof \DOMElement && strtolower($node->nodeName) === 'a') {
                // Новая ссылка — предыдущий элемент закончился.
                if ($current !== null) {
                    $items[] = $current;
                }

                $url = SanitizedHref::fromString($node->getAttribute('href'));
                $title = self::normalize($node->textContent);

                $current = ($url === null || $title === '')
                    ? null
                    : ['title' => $title, 'url' => $url, 'summary' => ''];

                continue;
            }

            if ($current === null) {
                continue;
            }

            // Текстовые узлы между ссылками — описание. <br> игнорируем,
            // но пробел вместо него ставим, иначе слова слипаются.
            if ($node instanceof \DOMElement && strtolower($node->nodeName) === 'br') {
                $current['summary'] .= ' ';

                continue;
            }

            $current['summary'] .= $node->textContent;
        }

        if ($current !== null) {
            $items[] = $current;
        }

        return array_values(array_filter(array_map(
            static function (array $item): array {
                $item['summary'] = self::normalize($item['summary']);

                return $item;
            },
            $items
        ), static fn (array $item): bool => mb_strlen($item['summary']) >= self::MIN_SUMMARY_LENGTH));
    }

    private static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Неразрывные пробелы из письма превращаем в обычные, иначе trim
        // их не снимает и строки выглядят с отступом.
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

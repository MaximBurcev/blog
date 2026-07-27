<?php

namespace Tests\Unit;

use App\Jobs\StorePostJob;
use DOMDocument;
use DOMXPath;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Регрессия на XPath injection (security-audit-2026-07-24 MIN-2):
 * selector задаётся вручную в админке и раньше подставлялся в XPath-запрос
 * без экранирования кавычек — значение вида `#foo'] | //script | //*[@id='`
 * ломало структуру запроса и могло вытащить произвольные узлы документа.
 */
class StorePostJobSelectorXPathTest extends TestCase
{
    private function buildXPath(string $selector): string
    {
        $method = new ReflectionMethod(StorePostJob::class, 'buildSelectorXPath');
        $method->setAccessible(true);

        return $method->invoke(new StorePostJob([]), $selector);
    }

    private function query(string $html, string $xpath): \DOMNodeList
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return (new DOMXPath($dom))->query($xpath);
    }

    public function test_id_selector_still_matches_normally(): void
    {
        $html = '<html><body><div id="article-body">content</div></body></html>';

        $nodes = $this->query($html, $this->buildXPath('#article-body'));

        $this->assertSame(1, $nodes->count());
    }

    public function test_class_selector_still_matches_normally(): void
    {
        $html = '<html><body><div class="article-body">content</div></body></html>';

        $nodes = $this->query($html, $this->buildXPath('.article-body'));

        $this->assertSame(1, $nodes->count());
    }

    public function test_injection_payload_in_id_selector_does_not_leak_other_nodes(): void
    {
        $html = '<html><body>'.
            '<div id="article-body">content</div>'.
            '<script>alert(1)</script>'.
            '<div id="secret">top secret</div>'.
            '</body></html>';

        $payload = "article-body'] | //script | //*[@id='secret";

        $nodes = $this->query($html, $this->buildXPath('#'.$payload));

        // Экранированный литерал ищет id, буквально равный всему payload —
        // такого узла нет, значит результат пуст, а не script/secret
        $this->assertSame(0, $nodes->count());
    }

    public function test_injection_payload_with_both_quote_types_is_escaped_via_concat(): void
    {
        $html = '<html><body><div class="safe">content</div></body></html>';

        $payload = '.safe\'" | //*[1'; // содержит и \' и "

        $xpath = $this->buildXPath($payload);

        // Не должно бросить исключение из-за синтаксически некорректного XPath
        $nodes = $this->query($html, $xpath);

        $this->assertSame(0, $nodes->count());
    }
}

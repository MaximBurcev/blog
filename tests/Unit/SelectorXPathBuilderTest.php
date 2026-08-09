<?php

namespace Tests\Unit;

use App\Support\SelectorXPathBuilder;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * Регрессия на XPath injection (security-audit-2026-07-24 MIN-2 и
 * security-audit-2026-08-01, латентная находка в TranslateService):
 * selector задаётся вручную в админке и раньше подставлялся в XPath-запрос
 * без экранирования кавычек — значение вида `#foo'] | //script | //*[@id='`
 * ломало структуру запроса и могло вытащить произвольные узлы документа.
 *
 * Билдер общий для StorePostJob::extractArticle() и TranslateService::translate().
 */
class SelectorXPathBuilderTest extends TestCase
{
    private function query(string $html, string $xpath): \DOMNodeList
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return (new DOMXPath($dom))->query($xpath);
    }

    public function test_id_selector_still_matches_normally(): void
    {
        $html = '<html><body><div id="article-body">content</div></body></html>';

        $nodes = $this->query($html, SelectorXPathBuilder::build('#article-body'));

        $this->assertSame(1, $nodes->count());
    }

    public function test_class_selector_still_matches_normally(): void
    {
        $html = '<html><body><div class="article-body">content</div></body></html>';

        $nodes = $this->query($html, SelectorXPathBuilder::build('.article-body'));

        $this->assertSame(1, $nodes->count());
    }

    public function test_bare_class_name_matches_like_dotted_selector(): void
    {
        $html = '<html><body><div class="crayons-article__body">content</div></body></html>';

        $nodes = $this->query($html, SelectorXPathBuilder::build('crayons-article__body'));

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

        $nodes = $this->query($html, SelectorXPathBuilder::build('#'.$payload));

        // Экранированный литерал ищет id, буквально равный всему payload —
        // такого узла нет, значит результат пуст, а не script/secret
        $this->assertSame(0, $nodes->count());
    }

    public function test_injection_payload_in_class_selector_does_not_leak_other_nodes(): void
    {
        $html = '<html><body>'.
            '<div class="article-body">content</div>'.
            '<script>alert(1)</script>'.
            '</body></html>';

        // Форма payload'а под старую прямую конкатенацию в TranslateService
        $payload = "article-body ')] | //script | //*[contains(concat(' ";

        $nodes = $this->query($html, SelectorXPathBuilder::build($payload));

        $this->assertSame(0, $nodes->count());
    }

    public function test_injection_payload_with_both_quote_types_is_escaped_via_concat(): void
    {
        $html = '<html><body><div class="safe">content</div></body></html>';

        $payload = '.safe\'" | //*[1'; // содержит и \' и "

        $xpath = SelectorXPathBuilder::build($payload);

        // Не должно бросить исключение из-за синтаксически некорректного XPath
        $nodes = $this->query($html, $xpath);

        $this->assertSame(0, $nodes->count());
    }
}

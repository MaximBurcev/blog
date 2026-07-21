<?php

namespace Tests\Unit;

use App\Service\ReleaseService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Регрессия: у javascriptweekly.com (и любого другого дайджеста с новым
 * per-domain селектором ссылок) секционная фильтрация по h2-заголовкам
 * (section_headings, заточена под td.bodyContent-вёрстку
 * mailer.inovica.com) полностью перебивала переданный домен-специфичный
 * селектор — extractLinksFromSections() игнорировала его и всегда искала
 * td.bodyContent, которого на других сайтах нет. Итог: "Ссылки не найдены"
 * даже при корректно настроенном селекторе.
 */
class ReleaseServiceLinkSelectorTest extends TestCase
{
    public function test_domain_specific_selector_is_resolved(): void
    {
        Config::set('releases.parser_selectors_by_domain', [
            'javascriptweekly.com' => 'li a',
        ]);

        $selector = $this->getLinkSelectorForUrl('https://javascriptweekly.com/issues/795');

        $this->assertSame('li a', $selector);
    }

    public function test_unknown_domain_has_no_override(): void
    {
        Config::set('releases.parser_selectors_by_domain', [
            'javascriptweekly.com' => 'li a',
        ]);

        $selector = $this->getLinkSelectorForUrl('https://mailer.inovica.com/newsletter.php?id=1164');

        $this->assertNull($selector);
    }

    public function test_domain_selector_bypasses_section_heading_filtering(): void
    {
        // Секции с "Articles" тут нет вообще — только li/a. Без фикса
        // extractLinksFromSections() искала бы td.bodyContent и вернула 0
        Config::set('releases.section_headings', ['Articles']);

        $html = '<html><body><ul>'.
            '<li><a href="https://example.test/a">First Story</a></li>'.
            '<li><a href="https://example.test/b">Second Story</a></li>'.
            '</ul></body></html>';

        $links = $this->extractLinksWithCrawler($html, 'li a');

        $this->assertCount(2, $links);
        $this->assertSame('https://example.test/a', $links[0]['url']);
    }

    public function test_without_domain_selector_section_heading_filtering_still_applies(): void
    {
        // Существующее поведение для mailer.inovica.com не должно сломаться:
        // без домен-специфичного селектора секционная фильтрация работает
        // как раньше
        Config::set('releases.section_headings', ['Articles']);

        $html = '<html><body>'.
            '<td class="bodyContent"><h2>Articles</h2><a href="https://example.test/a">Kept</a></td>'.
            '<td class="bodyContent"><h2>Other Section</h2><a href="https://example.test/b">Skipped</a></td>'.
            '</body></html>';

        $links = $this->extractLinksWithCrawler($html, null);

        $this->assertCount(1, $links);
        $this->assertSame('https://example.test/a', $links[0]['url']);
    }

    private function getLinkSelectorForUrl(string $url): ?string
    {
        $method = new \ReflectionMethod(ReleaseService::class, 'getLinkSelectorForUrl');
        $method->setAccessible(true);

        return $method->invoke(new ReleaseService, $url);
    }

    private function extractLinksWithCrawler(string $html, ?string $domainSelector): array
    {
        $method = new \ReflectionMethod(ReleaseService::class, 'extractLinksWithCrawler');
        $method->setAccessible(true);

        return $method->invoke(new ReleaseService, $html, $domainSelector);
    }
}

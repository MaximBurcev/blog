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
    /**
     * Значения повторяют config/releases.php, но заданы здесь: тест обязан
     * ловить регрессию в КОДЕ извлечения, а читая боевой конфиг, он менял бы
     * своё утверждение вместе с ним.
     */
    private const DIGEST_SELECTOR = 'span.mainlink a, table.el-md p a:first-of-type';

    private const SPONSOR_RULE = ['item' => 'table.el-item', 'marker' => '.tag-sponsor'];

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

        // td обязаны лежать внутри table: начиная с symfony/dom-crawler 7.4
        // разбор идёт по правилам HTML5, и одинокий <td> в <body> молча
        // выбрасывается — ровно как это делает браузер. В реальной рассылке
        // mailer.inovica.com таблица есть, поэтому фикстура её и повторяет.
        $html = '<html><body><table><tr>'.
            '<td class="bodyContent"><h2>Articles</h2><a href="https://example.test/a">Kept</a></td>'.
            '<td class="bodyContent"><h2>Other Section</h2><a href="https://example.test/b">Skipped</a></td>'.
            '</tr></table></body></html>';

        $links = $this->extractLinksWithCrawler($html, null);

        $this->assertCount(1, $links);
        $this->assertSame('https://example.test/a', $links[0]['url']);
    }

    /**
     * Регрессия: селектор 'li a:first-of-type' не видел заголовочные
     * материалы выпуска (<span class="mainlink">) — на выпуске 799 это 13
     * пропущенных статей из 35 ссылок, то есть всё, ради чего дайджест и
     * разбирается. Фикстура повторяет разметку Cooper Press: заголовочный
     * блок, рекламный блок с той же разметкой плюс метка, и компактная
     * секция, где ссылка лежит прямо в <p>.
     */
    public function test_javascript_weekly_selector_takes_headline_items_and_skips_ads(): void
    {
        $links = $this->extractLinksWithCrawler(
            $this->digestHtml(),
            self::DIGEST_SELECTOR,
            self::SPONSOR_RULE
        );

        // Именно assertSame по всему набору, а не assertContains по паре
        // адресов: селектор, начавший цеплять подвал выпуска или вторичные
        // ссылки, прошёл бы проверку на вхождение зелёным.
        $this->assertSame(
            ['https://example.test/headline', 'https://example.test/brief'],
            array_column($links, 'url')
        );
    }

    /**
     * Внутри компактной секции берётся только первая ссылка абзаца:
     * остальные — вторичные упоминания в тексте пункта (сайт проекта,
     * документация, апгрейд-гайд), статьями они не являются.
     *
     * Оговорка: ':first-of-type' считается среди сиблингов СВОЕГО родителя,
     * поэтому вторичная ссылка, обёрнутая в <b>/<strong>, под это правило не
     * попадёт и в очередь уедет. На выпусках 797–799 таких нет ни одной,
     * поэтому обходной механизм не заводился.
     */
    public function test_secondary_links_inside_a_paragraph_are_not_taken(): void
    {
        $links = $this->extractLinksWithCrawler($this->digestHtml(), self::DIGEST_SELECTOR, null);

        $this->assertNotContains('https://example.test/secondary', array_column($links, 'url'));
    }

    /**
     * Без правила отсева рекламный пункт неотличим от обычного — проверяем,
     * что отсекает именно метка, а не что-то в структуре фикстуры.
     */
    public function test_without_sponsor_rule_the_ad_is_kept(): void
    {
        $links = $this->extractLinksWithCrawler($this->digestHtml(), self::DIGEST_SELECTOR, null);

        $this->assertContains('https://example.test/ad', array_column($links, 'url'));
    }

    /**
     * Правило рекламы применяется и на второй ветке извлечения — секционной
     * (td.bodyContent + section_headings), которой разбирается PHP Weekly.
     * Иначе заведённое для такого дайджеста правило выглядело бы рабочим и
     * молча пропускало рекламу в очередь.
     */
    public function test_sponsor_rule_applies_on_the_section_branch_too(): void
    {
        Config::set('releases.section_headings', ['Articles']);

        $html = '<html><body><table><tr>'.
            '<td class="bodyContent"><h2>Articles</h2>'.
            '<table class="el-item"><tr><td><span class="tag-sponsor">sponsor</span>'.
            '<a href="https://example.test/ad">Buy Our Product</a></td></tr></table>'.
            '<a href="https://example.test/story">Real Story</a>'.
            '</td></tr></table></body></html>';

        $links = $this->extractLinksWithCrawler($html, null, self::SPONSOR_RULE);

        $this->assertSame(['https://example.test/story'], array_column($links, 'url'));
    }

    /**
     * Опечатка в селекторе правила приезжает из DomCrawler исключением, и
     * раньше оно вылетало наружу, обрывая разбор ВСЕГО выпуска. Реклама в
     * очереди видна и удаляется руками, ноль разобранных статей — нет.
     */
    public function test_broken_sponsor_selector_does_not_break_parsing(): void
    {
        $links = $this->extractLinksWithCrawler(
            $this->digestHtml(),
            self::DIGEST_SELECTOR,
            ['item' => 'table.el-item', 'marker' => '.tag-sponsor:has(']
        );

        $this->assertContains('https://example.test/headline', array_column($links, 'url'));
    }

    /**
     * Правило домена дайджеста не должно применяться к другому дайджесту:
     * разметка у них разная, и чужая метка отсекала бы нормальные статьи.
     */
    public function test_sponsor_rule_is_resolved_by_domain(): void
    {
        Config::set('releases.parser_sponsor_markers_by_domain', [
            'javascriptweekly.com' => self::SPONSOR_RULE,
        ]);

        $this->assertSame(self::SPONSOR_RULE, $this->getSponsorRuleForUrl('https://javascriptweekly.com/issues/799'));
        $this->assertNull($this->getSponsorRuleForUrl('https://mailer.inovica.com/newsletter.php?id=1164'));
    }

    /**
     * Нестроковое значение (копипаст массива) доходило до DomCrawler и падало
     * TypeError'ом — а его, в отличие от Exception, не ловит ни один catch по
     * пути наверх, то есть разбор выпуска умирал фаталом.
     */
    public function test_non_string_sponsor_rule_is_ignored(): void
    {
        Config::set('releases.parser_sponsor_markers_by_domain', [
            'javascriptweekly.com' => ['item' => ['table.el-item'], 'marker' => '.tag-sponsor'],
        ]);

        $this->assertNull($this->getSponsorRuleForUrl('https://javascriptweekly.com/issues/799'));
    }

    /**
     * Опечатка в конфиге (потерянный ключ пары) не должна ронять разбор
     * выпуска — правило просто не применяется.
     */
    public function test_incomplete_sponsor_rule_is_ignored(): void
    {
        Config::set('releases.parser_sponsor_markers_by_domain', [
            'javascriptweekly.com' => ['item' => 'table.el-item'],
        ]);

        $this->assertNull($this->getSponsorRuleForUrl('https://javascriptweekly.com/issues/799'));
    }

    private function digestHtml(): string
    {
        return '<html><body><div id="content">'.
            '<table class="el-item item"><tr><td>'.
            '<p class="desc"><span class="mainlink"><a href="https://example.test/headline">Headline Story</a></span> — описание</p>'.
            '</td></tr></table>'.
            '<table class="el-item item"><tr><td>'.
            '<p class="desc"><span class="tag-sponsor">sponsor</span></p>'.
            '<p class="desc"><span class="mainlink"><a href="https://example.test/ad">Buy Our Product</a></span></p>'.
            '</td></tr></table>'.
            '<table class="content el-md"><tr><td>'.
            '<ul><li><p><a href="https://example.test/brief">Brief Item</a>, подробнее в '.
            '<a href="https://example.test/secondary">документации</a></p></li></ul>'.
            '</td></tr></table>'.
            '</div></body></html>';
    }

    private function getSponsorRuleForUrl(string $url): ?array
    {
        $method = new \ReflectionMethod(ReleaseService::class, 'getSponsorRuleForUrl');
        $method->setAccessible(true);

        return $method->invoke(new ReleaseService, $url);
    }

    private function getLinkSelectorForUrl(string $url): ?string
    {
        $method = new \ReflectionMethod(ReleaseService::class, 'getLinkSelectorForUrl');
        $method->setAccessible(true);

        return $method->invoke(new ReleaseService, $url);
    }

    private function extractLinksWithCrawler(string $html, ?string $domainSelector, ?array $sponsorRule = null): array
    {
        $method = new \ReflectionMethod(ReleaseService::class, 'extractLinksWithCrawler');
        $method->setAccessible(true);

        return $method->invoke(new ReleaseService, $html, $domainSelector, $sponsorRule);
    }
}

<?php

namespace Tests\Unit;

use App\Support\NewsDigestParser;
use Tests\TestCase;

/**
 * Секция «News and Announcements» дайджеста PHP Weekly свёрстана плоско:
 * ссылка, <br>, текст описания, <br><br>, следующая ссылка. Обёртки на
 * элемент нет вовсе, поэтому разбор идёт по узлам, а не по селектору.
 */
class NewsDigestParserTest extends TestCase
{
    private function digest(string $inner, string $heading = 'News and Announcements'): string
    {
        return '<html><body><table><tr><td class="bodyContent">'.
            '<h2>'.$heading.'</h2><br>'.$inner.
            '</td></tr></table></body></html>';
    }

    private function item(string $url, string $title, string $summary): string
    {
        return '<a href="'.$url.'">'.$title.'</a><br>'.$summary.'<br><br>';
    }

    public function test_extracts_title_url_and_summary(): void
    {
        $html = $this->digest(
            $this->item('https://thephp.foundation/blog/q2/', 'Quarterly Progress Report',
                'Our team of core developers works very hard to strengthen the PHP language.')
        );

        $items = NewsDigestParser::parse($html, 'News and Announcements');

        $this->assertCount(1, $items);
        $this->assertSame('Quarterly Progress Report', $items[0]['title']);
        $this->assertSame('https://thephp.foundation/blog/q2/', $items[0]['url']);
        $this->assertStringContainsString('core developers', $items[0]['summary']);
    }

    public function test_extracts_several_items(): void
    {
        $html = $this->digest(
            $this->item('https://a.test/1', 'Первая', str_repeat('описание достаточной длины ', 3)).
            $this->item('https://b.test/2', 'Вторая', str_repeat('другое описание подлиннее ', 3))
        );

        $items = NewsDigestParser::parse($html, 'News and Announcements');

        $this->assertCount(2, $items);
        $this->assertSame(['Первая', 'Вторая'], array_column($items, 'title'));
    }

    /**
     * В дайджесте несколько секций с одинаковой вёрсткой — берём только ту,
     * что просили, иначе в новости попадут статьи и вакансии.
     */
    public function test_takes_only_the_requested_section(): void
    {
        $html = '<html><body><table><tr>'.
            '<td class="bodyContent"><h2>Articles</h2><br>'.
            $this->item('https://a.test/article', 'Статья', str_repeat('текст статьи подлиннее ', 3)).
            '</td>'.
            '<td class="bodyContent"><h2>News and Announcements</h2><br>'.
            $this->item('https://b.test/news', 'Новость', str_repeat('текст новости подлиннее ', 3)).
            '</td>'.
            '</tr></table></body></html>';

        $items = NewsDigestParser::parse($html, 'News and Announcements');

        $this->assertCount(1, $items);
        $this->assertSame('Новость', $items[0]['title']);
    }

    public function test_returns_empty_when_section_is_absent(): void
    {
        $html = $this->digest($this->item('https://a.test/1', 'Т', str_repeat('описание ', 8)), 'Jobs');

        $this->assertSame([], NewsDigestParser::parse($html, 'News and Announcements'));
    }

    public function test_returns_empty_on_blank_html(): void
    {
        $this->assertSame([], NewsDigestParser::parse('', 'News and Announcements'));
        $this->assertSame([], NewsDigestParser::parse('   ', 'News and Announcements'));
    }

    /**
     * Ссылки приходят из чужого письма: схему проверяет SanitizedHref, и
     * элемент с javascript: не должен попасть в результат вовсе.
     */
    public function test_drops_items_with_dangerous_scheme(): void
    {
        $html = $this->digest(
            $this->item('javascript:alert(1)', 'Вредная', str_repeat('описание достаточной длины ', 3)).
            $this->item('https://ok.test/2', 'Нормальная', str_repeat('другое описание подлиннее ', 3))
        );

        $items = NewsDigestParser::parse($html, 'News and Announcements');

        $this->assertCount(1, $items);
        $this->assertSame('Нормальная', $items[0]['title']);
    }

    /**
     * Служебные ссылки в конце секции («unsubscribe», иконки) описания не
     * имеют — попадать в ленту они не должны.
     */
    public function test_skips_items_without_meaningful_summary(): void
    {
        $html = $this->digest(
            '<a href="https://a.test/1">Только ссылка</a><br><br>'.
            $this->item('https://b.test/2', 'С описанием', str_repeat('нормальное описание ', 3))
        );

        $items = NewsDigestParser::parse($html, 'News and Announcements');

        $this->assertCount(1, $items);
        $this->assertSame('С описанием', $items[0]['title']);
    }

    public function test_summary_threshold_is_adjustable(): void
    {
        $html = $this->digest(
            $this->item('https://a.test/1', 'yajra/laravel-datatables-html', 'Laravel DataTables HTML builder plugin.')
        );

        $this->assertCount(0, NewsDigestParser::parse($html, 'News and Announcements'));
        $this->assertCount(1, NewsDigestParser::parse($html, 'News and Announcements', 20));
    }

    public function test_collapses_whitespace_and_nbsp(): void
    {
        $html = $this->digest(
            '<a href="https://a.test/1">  Заголовок&nbsp;с&nbsp;пробелами  </a><br>'.
            "Описание\n\n   с    переносами и достаточной длиной строки.<br><br>"
        );

        $items = NewsDigestParser::parse($html, 'News and Announcements');

        $this->assertSame('Заголовок с пробелами', $items[0]['title']);
        $this->assertStringNotContainsString('  ', $items[0]['summary']);
    }
}

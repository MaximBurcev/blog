<?php

namespace Tests\Unit;

use App\Support\PostTableOfContents;
use PHPUnit\Framework\TestCase;

class PostTableOfContentsTest extends TestCase
{
    public function test_anchors_and_items_are_built_from_cyrillic_headings(): void
    {
        $toc = new PostTableOfContents(
            '<h2>Введение в очереди</h2><p>Текст</p><h3>Тонкости настройки</h3><h2>Итоги и выводы</h2>'
        );

        $this->assertSame([
            ['id' => 'section-1', 'title' => 'Введение в очереди', 'level' => 2],
            ['id' => 'section-2', 'title' => 'Тонкости настройки', 'level' => 3],
            ['id' => 'section-3', 'title' => 'Итоги и выводы', 'level' => 2],
        ], $toc->items());

        $this->assertStringContainsString('<h2 id="section-1">Введение в очереди</h2>', $toc->content());
        $this->assertStringContainsString('<h3 id="section-2">Тонкости настройки</h3>', $toc->content());
    }

    public function test_no_toc_and_content_untouched_when_fewer_than_three_headings(): void
    {
        $html = '<h2>Раз</h2><p>Текст</p><h2>Два</h2>';

        $toc = new PostTableOfContents($html);

        $this->assertSame([], $toc->items());
        $this->assertSame($html, $toc->content());
    }

    public function test_existing_heading_ids_are_preserved(): void
    {
        $toc = new PostTableOfContents('<h2 id="intro">Раз</h2><h2>Два</h2><h2>Три</h2>');

        // Свой id не трогаем (у скрейпленных статей бывают свои якоря),
        // нумерация при этом идёт сплошная по всем заголовкам.
        $this->assertSame('intro', $toc->items()[0]['id']);
        $this->assertSame('section-2', $toc->items()[1]['id']);
        $this->assertStringContainsString('<h2 id="intro">Раз</h2>', $toc->content());
    }

    public function test_code_blocks_do_not_produce_headings_and_stay_escaped(): void
    {
        // В контенте постов листинги лежат экранированными — «заголовок»
        // внутри <pre> для DOM это текст, а не разметка, и оглавление его
        // не должно увидеть.
        $html = '<pre>&lt;h2&gt;Это код, а не заголовок&lt;/h2&gt;</pre><h2>Раз</h2><h2>Два</h2><h2>Три</h2>';

        $toc = new PostTableOfContents($html);

        $this->assertCount(3, $toc->items());
        $this->assertStringContainsString('&lt;h2&gt;Это код, а не заголовок&lt;/h2&gt;', $toc->content());
    }
}

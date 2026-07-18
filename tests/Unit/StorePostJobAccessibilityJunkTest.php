<?php

namespace Tests\Unit;

use App\Jobs\StorePostJob;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/**
 * Регрессия: "article" на medium.com/gitconnected.com/stackademic.com
 * захватывает не только текст статьи, но и шапку автора (аватар, имя,
 * время чтения, дата, кнопки Слушать/Поделиться) — она рендерилась
 * прямо в теле поста. Найдено на реальном посте
 * (ecnmee.medium.com/the-4-memory-layers...).
 */
class StorePostJobAccessibilityJunkTest extends TestCase
{
    public function test_strips_author_byline_chrome(): void
    {
        $result = $this->strip(
            '<h1>Real Title</h1>'.
            '<div class="speechify-ignore"><a href="/author"><img src="a.png"></a>'.
            '<span>Author Name</span><br>6 min read<br>·2 July 2026</div>'.
            '<p>Real article paragraph.</p>'
        );

        $this->assertStringContainsString('Real Title', $result);
        $this->assertStringContainsString('Real article paragraph.', $result);
        $this->assertStringNotContainsString('Author Name', $result);
        $this->assertStringNotContainsString('6 min read', $result);
    }

    public function test_strips_image_zoom_hint(): void
    {
        $result = $this->strip(
            '<p>Before.</p>'.
            '<figure><span class="speechify-ignore">Press enter or click to view image in full size</span>'.
            '<img src="a.png"></figure>'.
            '<p>After.</p>'
        );

        $this->assertStringContainsString('Before.', $result);
        $this->assertStringContainsString('After.', $result);
        $this->assertStringNotContainsString('Press enter', $result);
    }

    public function test_does_not_touch_content_without_the_marker(): void
    {
        $html = '<h1>Title</h1><p>Ordinary paragraph, nothing to strip.</p>';

        $this->assertSame($html, $this->strip($html));
    }

    public function test_removes_multiple_nested_junk_elements(): void
    {
        // Вложенные div'ы оба несут класс — проверяем, что удаление
        // одного не мешает найти и удалить остальные (iterator_to_array
        // снимает снимок перед мутацией DOM)
        $result = $this->strip(
            '<div class="speechify-ignore outer"><div class="speechify-ignore inner">junk</div></div>'.
            '<p>Keep me.</p>'
        );

        $this->assertStringContainsString('Keep me.', $result);
        $this->assertStringNotContainsString('junk', $result);
    }

    private function strip(string $bodyHtml): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$bodyHtml.'</body>');
        $finder = new DOMXPath($dom);

        $job = new StorePostJob(['url' => 'https://example.test']);
        $method = new \ReflectionMethod(StorePostJob::class, 'stripAccessibilityJunk');
        $method->setAccessible(true);
        $method->invoke($job, $finder);

        $out = '';
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }
}

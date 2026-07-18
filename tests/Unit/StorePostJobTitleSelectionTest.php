<?php

namespace Tests\Unit;

use App\Jobs\StorePostJob;
use DOMDocument;
use Tests\TestCase;

/**
 * Тесты на выбор article-заголовка среди нескольких <h1> на странице.
 *
 * Баг: сайты с h1-логотипом в шапке (<h1><a href="/">SiteName</a></h1>)
 * отдавали название сайта вместо заголовка статьи — item(0) брал первый
 * h1 без разбора (см. stitcher.io: "шитье.io" вместо реального заголовка).
 */
class StorePostJobTitleSelectionTest extends TestCase
{
    public function test_skips_site_logo_h1_and_picks_article_title(): void
    {
        $title = $this->selectTitle(
            '<h1><a href="/">stitcher.io</a></h1>'.
            '<h1>"A" for "Artificial"</h1>'
        );

        $this->assertSame('"A" for "Artificial"', $title);
    }

    public function test_single_h1_is_used_as_is(): void
    {
        $title = $this->selectTitle('<h1>Just One Title</h1>');

        $this->assertSame('Just One Title', $title);
    }

    public function test_logo_link_to_hash_is_also_recognized(): void
    {
        $title = $this->selectTitle(
            '<h1><a href="#">Site Name</a></h1>'.
            '<h1>Real Article Title</h1>'
        );

        $this->assertSame('Real Article Title', $title);
    }

    public function test_h1_with_link_plus_extra_text_is_not_treated_as_logo(): void
    {
        // Ссылка внутри h1 есть, но это не логотип — вокруг есть свой текст
        $title = $this->selectTitle(
            '<h1><a href="/">stitcher.io</a></h1>'.
            '<h1>Read more on <a href="/about">our site</a> today</h1>'
        );

        $this->assertSame('Read more on our site today', $title);
    }

    public function test_all_h1_are_logos_falls_back_to_first(): void
    {
        // Нет ни одного "нормального" h1 — не должны падать, берём первый
        $title = $this->selectTitle(
            '<h1><a href="/">Site A</a></h1>'.
            '<h1><a href="/">Site A</a></h1>'
        );

        $this->assertSame('Site A', $title);
    }

    public function test_link_to_other_page_is_not_a_logo(): void
    {
        // Ссылка есть, но ведёт не на главную — это реальный заголовок-ссылка,
        // не логотип сайта
        $title = $this->selectTitle(
            '<h1><a href="/">stitcher.io</a></h1>'.
            '<h1><a href="/blog/some-other-post">Article referencing another post</a></h1>'
        );

        $this->assertSame('Article referencing another post', $title);
    }

    private function selectTitle(string $bodyHtml): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$bodyHtml.'</body>');

        $h1Nodes = $dom->getElementsByTagName('h1');

        $job = new StorePostJob(['url' => 'https://example.test']);
        $method = new \ReflectionMethod(StorePostJob::class, 'selectArticleTitleNode');
        $method->setAccessible(true);

        return $method->invoke($job, $h1Nodes)->nodeValue;
    }
}

<?php

namespace Tests\Unit;

use App\Service\HtmlSanitizerService;
use Tests\TestCase;

/**
 * Код в теле статьи обязан остаться внутри <pre><code>.
 *
 * Регрессия 25.08.2026, пост 251. Shiki, Prism с нумерацией строк и torchlight
 * рендерят каждую строку кода отдельным <div class="line"> ВНУТРИ <code>. По
 * модели контента HTML это недопустимо — <code> строчный, <div> блочный, — и
 * HTMLPurifier честно выносит блоки наружу: остаётся пустой
 * <pre><code></code></pre>, а код превращается в россыпь соседних
 * <div><code>…</code></div>. На странице это тёмная пустая полоса, под которой
 * строки кода идут обычным текстом через интервал. Так побились 15 постов из 211.
 */
class HtmlSanitizerCodeBlockTest extends TestCase
{
    public function test_code_split_into_line_divs_stays_inside_the_block(): void
    {
        $html = '<pre class="language-php"><code>'
            .'<div class="line"><span>&lt;?php</span></div>'
            .'<div class="line"><span>namespace App;</span></div>'
            .'<div class="line"></div>'
            .'<div class="line"><span>echo 1;</span></div>'
            .'</code></pre>';

        $result = $this->sanitizer()->sanitize($html);

        $this->assertStringNotContainsString(
            '<pre><code></code></pre>',
            $result,
            'блок кода остался пустым — код уехал наружу',
        );

        // Код внутри блока и построчно: без переносов все строки слиплись бы
        // в одну, потому что <div> схлопывается, а разделителя не остаётся.
        $this->assertMatchesRegularExpression('#<pre><code>.*&lt;\?php\nnamespace App;#s', $result);
        $this->assertStringContainsString('echo 1;</code></pre>', $result);
    }

    public function test_ordinary_highlighted_block_is_left_alone(): void
    {
        // Разметка без блочных тегов HTMLPurifier не ломает, и переписывать её
        // незачем: <span> подсветки переживают очистку как были.
        $html = '<pre><code><span class="k">echo</span> 1;</code></pre>';

        $result = $this->sanitizer()->sanitize($html);

        $this->assertStringContainsString('<span>echo</span> 1;', $result);
        $this->assertStringContainsString('<pre><code>', $result);
    }

    public function test_plain_code_block_survives(): void
    {
        $html = '<pre><code>composer require foo/bar</code></pre>';

        $this->assertStringContainsString(
            'composer require foo/bar</code></pre>',
            $this->sanitizer()->sanitize($html),
        );
    }

    public function test_entities_are_not_decoded_into_live_markup(): void
    {
        // В исходнике код уже экранирован. Раскодировав его при схлопывании,
        // мы бы получили на выходе настоящий тег — то есть XSS из чужой статьи.
        $html = '<pre><code><div class="line">&lt;script&gt;alert(1)&lt;/script&gt;</div></code></pre>';

        $result = $this->sanitizer()->sanitize($html);

        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    private function sanitizer(): HtmlSanitizerService
    {
        return app(HtmlSanitizerService::class);
    }
}

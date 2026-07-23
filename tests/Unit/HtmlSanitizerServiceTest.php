<?php

namespace Tests\Unit;

use App\Service\HtmlSanitizerService;
use Tests\TestCase;

/**
 * Контент постов — чужой скрейпленный HTML, рендерится через
 * {!! $post->content !!} без экранирования (см. post/show.blade.php,
 * admin/posts/show.blade.php) — санитайзер должен вырезать всё, что может
 * исполниться в браузере, но сохранять обычную разметку статьи.
 */
class HtmlSanitizerServiceTest extends TestCase
{
    public function test_strips_script_tags(): void
    {
        $result = (new HtmlSanitizerService)->sanitize('<p>Text</p><script>alert(1)</script>');

        $this->assertStringContainsString('<p>Text</p>', $result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function test_strips_event_handler_attributes(): void
    {
        $result = (new HtmlSanitizerService)->sanitize('<img src="a.png" onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $result);
    }

    public function test_strips_javascript_uri_in_link(): void
    {
        $result = (new HtmlSanitizerService)->sanitize('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function test_strips_iframe(): void
    {
        $result = (new HtmlSanitizerService)->sanitize('<p>Before</p><iframe src="https://evil.test"></iframe><p>After</p>');

        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
    }

    public function test_preserves_common_article_markup(): void
    {
        $html = '<h2>Title</h2><p>Some <strong>bold</strong> and <a href="https://example.com">a link</a>.</p>'.
            '<ul><li>one</li><li>two</li></ul><pre><code>echo 1;</code></pre>'.
            '<div><img src="https://example.com/a.png" alt="pic" width="100" height="50"></div>';

        $result = (new HtmlSanitizerService)->sanitize($html);

        $this->assertStringContainsString('<h2>Title</h2>', $result);
        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('<li>one</li>', $result);
        $this->assertStringContainsString('<code>echo 1;</code>', $result);
        $this->assertStringContainsString('src="https://example.com/a.png"', $result);
    }
}

<?php

namespace Tests\Feature;

use App\Service\ContentImageService;
use Tests\TestCase;

class ContentImageServiceAbsolutizeTest extends TestCase
{
    private function service(): ContentImageService
    {
        return app(ContentImageService::class);
    }

    public function test_relative_src_becomes_absolute(): void
    {
        $html = '<p><img src="/uploads/pic.png" alt=""></p>';

        $out = $this->service()->absolutizeImageUrls($html, 'https://example.com/blog/post-1');

        $this->assertStringContainsString('src="https://example.com/uploads/pic.png"', $out);
    }

    public function test_protocol_relative_src_gets_scheme(): void
    {
        $html = '<img src="//cdn.example.com/pic.png">';

        $out = $this->service()->absolutizeImageUrls($html, 'https://example.com/a');

        $this->assertStringContainsString('src="https://cdn.example.com/pic.png"', $out);
    }

    public function test_next_image_optimizer_is_unwrapped_to_cdn_url(): void
    {
        // Реальный случай anthropic.com: src ведёт на /_next/image источника,
        // а не на картинку; &amp; из HTML должно декодироваться до запроса.
        $html = '<img src="/_next/image?url=https%3A%2F%2Fcdn.example.com%2Fimg%2Fa.png&amp;w=3840&amp;q=75">';

        $out = $this->service()->absolutizeImageUrls($html, 'https://example.com/a');

        $this->assertStringContainsString('src="https://cdn.example.com/img/a.png"', $out);
    }

    public function test_absolute_external_src_is_untouched(): void
    {
        $html = '<img src="https://cdn.other.com/pic.png?x=1&amp;y=2">';

        $out = $this->service()->absolutizeImageUrls($html, 'https://example.com/a');

        // Сущности декодируются до рабочего адреса, хост не переписывается.
        $this->assertStringContainsString('src="https://cdn.other.com/pic.png?x=1&y=2"', $out);
    }

    public function test_invalid_base_url_leaves_content_as_is(): void
    {
        $html = '<img src="/uploads/pic.png">';

        $this->assertSame($html, $this->service()->absolutizeImageUrls($html, ''));
    }

    public function test_data_src_is_not_rewritten(): void
    {
        $html = '<img data-src="/lazy.png" src="/real.png">';

        $out = $this->service()->absolutizeImageUrls($html, 'https://example.com/a');

        $this->assertStringContainsString('data-src="/lazy.png"', $out);
        $this->assertStringContainsString('src="https://example.com/real.png"', $out);
    }
}

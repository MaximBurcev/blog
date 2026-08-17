<?php

namespace Tests\Unit;

use App\Service\DiagramTranslatorService;
use Illuminate\Support\Facades\Log;
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

    /**
     * Регрессия: на проде Tesseract не был установлен, а вызов глушил stderr —
     * «command not found» приходил пустой строкой и трактовался как «на
     * картинке нет текста». Лог показывал штатную работу (124 записи
     * «no text detected», ноль перерисовок), пока причину не нашли руками.
     */
    public function test_missing_ocr_binary_is_reported_as_a_problem(): void
    {
        config(['releases.ocr_binary' => '/nonexistent/tesseract']);
        Log::spy();

        $path = $this->makeTestImage('Hello world');
        // Переводчик подменён, хотя до него дойти не должно: если ветку
        // сломают, тест обязан упасть на ассерте, а не уйти в сеть за
        // настоящим Google Translate и повиснуть там без интернета.
        $service = new DiagramTranslatorService($this->fakeTranslator('Привет мир'));

        $this->assertFalse($service->translate($path));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'OCR'))
            ->once();
        // Именно эта запись раньше и вводила в заблуждение.
        Log::shouldNotHaveReceived('info', [\Mockery::pattern('/no text detected/'), \Mockery::any()]);

        @unlink($path);
    }

    /**
     * Вторая половина того же фикса: когда OCR отработал и текста на картинке
     * правда нет, сообщение должно остаться прежним. Иначе «починить» баг
     * можно было бы, схлопнув оба случая обратно в один.
     */
    public function test_blank_image_is_still_reported_as_having_no_text(): void
    {
        if (! $this->tesseractAvailable()) {
            $this->markTestSkipped('tesseract бинарник недоступен в этом окружении');
        }

        Log::spy();

        $path = $this->makeBlankImage();
        $service = new DiagramTranslatorService($this->fakeTranslator('Привет мир'));

        $this->assertFalse($service->translate($path));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message): bool => str_contains($message, 'no text detected'))
            ->once();

        @unlink($path);
    }

    private function fakeTranslator(string $result): GoogleTranslate
    {
        return new class($result) extends GoogleTranslate
        {
            public function __construct(private readonly string $result)
            {
                parent::__construct('ru');
            }

            public function translate(string $string): ?string
            {
                return $this->result;
            }
        };
    }

    private function makeBlankImage(): string
    {
        $image = imagecreatetruecolor(200, 80);
        imagefilledrectangle($image, 0, 0, 200, 80, imagecolorallocate($image, 255, 255, 255));

        $path = tempnam(sys_get_temp_dir(), 'diagram_blank_').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
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

<?php

namespace Tests\Unit;

use App\Service\DiagramTranslatorService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Фраза, разорванная переносом строки, обязана уходить в переводчик
     * целиком.
     *
     * На обложке dev.to «The Easiest Way to Look Up» / «GeoIP in Laravel»
     * первая строка заканчивалась висящим фразовым глаголом, переводчик видел
     * её отдельно и выдавал «Самый простой способ посмотреть вверх» вместо
     * «найти». Здесь проверяется вход переводчика, а не картинка: разрыв
     * контекста происходит именно на этой границе.
     */
    public function test_wrapped_phrase_is_translated_as_a_whole(): void
    {
        if (! $this->tesseractAvailable()) {
            $this->markTestSkipped('tesseract бинарник недоступен в этом окружении');
        }

        $path = $this->makeMultilineImage(['The Easiest Way to Look Up', 'GeoIP in Laravel']);

        $translator = new class extends GoogleTranslate
        {
            /** @var array<int, string> */
            public array $seen = [];

            public function __construct()
            {
                parent::__construct('ru');
            }

            public function translate(string $string): ?string
            {
                $this->seen[] = $string;

                return 'Самый простой способ найти GeoIP в Laravel';
            }
        };

        (new DiagramTranslatorService($translator))->translate($path);

        $this->assertNotEmpty($translator->seen, 'OCR не отдал ни одной строки — проверять нечего');

        $longest = $translator->seen[0];
        foreach ($translator->seen as $seen) {
            $longest = mb_strlen($seen) > mb_strlen($longest) ? $seen : $longest;
        }

        // Обе половины пришли одним куском. Слова взяты те, что OCR читает
        // уверенно: «Up» он на синтетической картинке видит как «Ur», а
        // «GeoIP» как «GeolP» — проверять надо факт склейки, а не качество
        // распознавания.
        $this->assertStringContainsString('Easiest', $longest, 'первая строка не попала в перевод');
        $this->assertStringContainsString('Laravel', $longest, 'вторая строка переведена отдельно — контекст фразы потерян');

        @unlink($path);
    }

    /**
     * Логотипы и значки Tesseract читает как текст: DEV в чёрном квадрате
     * приходит строкой «o m &lt;», значок автора — «®». Переводить их значит
     * замазать чужой логотип и написать поверх «ом&lt;» — что и происходило на
     * обложке dev.to.
     */
    #[DataProvider('glyphNoise')]
    public function test_glyph_noise_is_not_translated(string $text): void
    {
        $this->assertTrue($this->isGlyphNoise($text), "«{$text}» должно отсеиваться как обрывки глифов");
    }

    #[DataProvider('realText')]
    public function test_real_text_is_still_translated(string $text): void
    {
        $this->assertFalse($this->isGlyphNoise($text), "«{$text}» — осмысленный текст, его надо переводить");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function glyphNoise(): array
    {
        return [
            'логотип DEV' => ['o m <'],
            'значок' => ['®'],
            'одна буква' => ['A'],
            'номер' => ['3'],
            'мусор' => ['|| ~ ='],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realText(): array
    {
        return [
            'подпись' => ['IPRout Team Aug 3'],
            'заголовок' => ['The Easiest Way to Look Up GeoIP in Laravel'],
            'короткая метка' => ['WORKING'],
            'метка с числом' => ['Step 2 done'],
        ];
    }

    /**
     * Раскладка перевода по строкам оригинала — самая рискованная часть
     * склейки, и от OCR она не зависит: проверяем напрямую, без картинки.
     */
    public function test_layout_fills_every_line_of_the_paragraph(): void
    {
        $lines = [
            ['left' => 0, 'top' => 0, 'width' => 300, 'height' => 30],
            ['left' => 0, 'top' => 40, 'width' => 300, 'height' => 30],
        ];

        $layout = $this->layout('Самый простой способ найти GeoIP в Laravel', $lines);

        $this->assertNotNull($layout);
        $this->assertCount(2, $layout['rows'], 'строк в раскладке должно быть ровно столько, сколько в оригинале');
        // Ни одно слово не потеряно и порядок сохранён.
        $this->assertSame(
            'Самый простой способ найти GeoIP в Laravel',
            trim(implode(' ', array_filter($layout['rows']))),
        );
    }

    public function test_layout_leaves_trailing_lines_empty_when_translation_is_shorter(): void
    {
        $lines = [
            ['left' => 0, 'top' => 0, 'width' => 400, 'height' => 30],
            ['left' => 0, 'top' => 40, 'width' => 400, 'height' => 30],
        ];

        $layout = $this->layout('Коротко', $lines);

        $this->assertNotNull($layout);
        // Хвостовая строка пустая: её бокс всё равно закрашивается, поэтому
        // исходный английский текст со второй строки исчезнет.
        $this->assertSame('Коротко', $layout['rows'][0]);
        $this->assertSame('', $layout['rows'][1]);
    }

    public function test_layout_refuses_when_a_single_word_is_wider_than_its_line(): void
    {
        // Узкий бокс и слово, которое не разорвать: раскладка обязана
        // отказаться, а не обрезать текст молча.
        $layout = $this->layout(
            str_repeat('длинноеслово', 5),
            [['left' => 0, 'top' => 0, 'width' => 20, 'height' => 12]],
        );

        $this->assertNull($layout);
    }

    public function test_layout_survives_zero_height_line(): void
    {
        // Вырожденный бокс от OCR не должен ронять перерисовку.
        $layout = $this->layout('Текст', [['left' => 0, 'top' => 0, 'width' => 400, 'height' => 0]]);

        $this->assertNotNull($layout);
        $this->assertSame('Текст', $layout['rows'][0]);
    }

    public function test_layout_shrinks_font_to_fit_the_longer_translation(): void
    {
        $roomy = $this->layout('Слово', [['left' => 0, 'top' => 0, 'width' => 400, 'height' => 30]]);
        $tight = $this->layout('Слово', [['left' => 0, 'top' => 0, 'width' => 60, 'height' => 30]]);

        $this->assertNotNull($roomy);
        $this->assertNotNull($tight);
        $this->assertLessThan($roomy['font_size'], $tight['font_size'], 'в узком боксе кегль обязан уменьшиться');
    }

    /**
     * @param  array<int, array{left: int, top: int, width: int, height: int}>  $lines
     * @return array{font_size: int, rows: array<int, string>}|null
     */
    private function layout(string $text, array $lines): ?array
    {
        $method = new \ReflectionMethod(DiagramTranslatorService::class, 'layoutParagraph');

        return $method->invoke(new DiagramTranslatorService, $text, $lines);
    }

    private function isGlyphNoise(string $text): bool
    {
        $method = new \ReflectionMethod(DiagramTranslatorService::class, 'looksLikeGlyphNoise');

        return $method->invoke(new DiagramTranslatorService, $text);
    }

    private function makeMultilineImage(array $lines): string
    {
        $image = imagecreatetruecolor(560, 160);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 560, 160, $white);

        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $y = 60;

        foreach ($lines as $line) {
            // Настоящий TTF, а не imagestring: встроенный растровый шрифт GD
            // Tesseract распознаёт неуверенно, и строки отсеивались бы по
            // MIN_CONFIDENCE ещё до группировки.
            imagettftext($image, 26, 0, 30, $y, $black, $font, $line);
            $y += 55;
        }

        $path = tempnam(sys_get_temp_dir(), 'diagram_multiline_').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
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

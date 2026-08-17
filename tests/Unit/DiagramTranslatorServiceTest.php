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
     * Режим обложки: переводится только самый крупный блок.
     *
     * На обложке dev.to Tesseract сваливает значок автора, его имя и логотип
     * DEV в одну «строку» шириной во всю картинку. Её бокс накрывает логотип,
     * и закраска стирала его — не потому что перевели, а потому что он внутри.
     * Плюс имя автора («Software Solutions» → «Разработчики программных
     * решений») переводить нельзя, а отличить его от текста нечем.
     */
    public function test_heading_only_mode_keeps_the_largest_text_block(): void
    {
        // Числа — фактический вывод Tesseract на обложке dev.to.
        $paragraphs = [
            // Заголовок: три строки крупным кеглем.
            ['text' => 'File Upload Best Practices', 'word_heights' => [55, 70, 53, 56, 55, 55, 68, 71, 54, 69], 'lines' => [
                ['left' => 96, 'top' => 146, 'width' => 880, 'height' => 70],
                ['left' => 96, 'top' => 235, 'width' => 730, 'height' => 68],
            ]],
            // Подпись: мелкий текст, но с логотипом высотой 51 — по габариту
            // строки (51) она почти догоняет заголовок и раньше проходила
            // фильтр целиком, утаскивая с собой логотип DEV.
            ['text' => 'Software Solutions DEV Jul 27', 'word_heights' => [19, 2, 51, 30, 30, 23, 23], 'lines' => [
                ['left' => 96, 'top' => 472, 'width' => 1000, 'height' => 51],
            ]],
        ];

        $kept = $this->keepLargest($paragraphs);

        $this->assertCount(1, $kept);
        $this->assertSame('File Upload Best Practices', $kept[0]['text']);
        // Обе строки заголовка на месте: разница в высоте между ними не должна
        // отрезать половину фразы.
        $this->assertCount(2, $kept[0]['lines']);
    }

    public function test_heading_only_mode_keeps_everything_when_text_is_uniform(): void
    {
        // Диаграмма с подписями одного кегля: отбирать нечего, режим не должен
        // выкидывать половину.
        $paragraphs = [
            ['text' => 'Queue', 'word_heights' => [20], 'lines' => [['left' => 0, 'top' => 0, 'width' => 100, 'height' => 20]]],
            ['text' => 'Worker', 'word_heights' => [19], 'lines' => [['left' => 0, 'top' => 40, 'width' => 100, 'height' => 19]]],
        ];

        $this->assertCount(2, $this->keepLargest($paragraphs));
    }

    /**
     * Короткий перевод, уложившийся в одну строку из двух, обязан остаться
     * центрированным.
     *
     * Выравнивание по левому краю включается для многострочного текста —
     * абзац не должен идти лесенкой. Но считать надо строки, которые реально
     * будут нарисованы: если перевод уложился в первую, а вторая осталась
     * пустой, видимая строка одна, и прижимать её влево незачем — оригинал
     * стоял по центру.
     */
    public function test_short_translation_keeps_single_line_centered(): void
    {
        if (! $this->tesseractAvailable()) {
            $this->markTestSkipped('tesseract бинарник недоступен в этом окружении');
        }

        // Две строки по центру, перевод вдвое короче оригинала.
        $path = $this->makeMultilineImage(['Some Very Long Heading Here', 'That Wraps Around'], centered: true);
        $service = new DiagramTranslatorService($this->fakeTranslator('Коротко'));

        $this->assertTrue($service->translate($path));

        $ink = $this->inkColumns($path);
        $this->assertNotSame([], $ink, 'на картинке не осталось текста');

        $canvasCentre = 560 / 2;
        $textCentre = (min($ink) + max($ink)) / 2;

        // Текст остался около центра, а не уехал к левому краю.
        $this->assertLessThan(60, abs($textCentre - $canvasCentre), "центр текста {$textCentre} слишком далеко от центра картинки {$canvasCentre}");

        @unlink($path);
    }

    /**
     * Колонки, в которых на белом фоне есть тёмные пиксели, — грубый способ
     * узнать, где на картинке лежит текст.
     *
     * @return array<int, int>
     */
    private function inkColumns(string $path): array
    {
        $image = imagecreatefrompng($path);
        $columns = [];

        for ($x = 0; $x < imagesx($image); $x++) {
            for ($y = 0; $y < imagesy($image); $y++) {
                $rgb = imagecolorat($image, $x, $y);

                if ((($rgb >> 16) & 0xFF) < 128) {
                    $columns[] = $x;
                    break;
                }
            }
        }

        imagedestroy($image);

        return $columns;
    }

    /**
     * @param  array<int, array{text: string, word_heights: array<int, int>, lines: array<int, array{left: int, top: int, width: int, height: int}>}>  $paragraphs
     * @return array<int, array{text: string, word_heights: array<int, int>, lines: array<int, array{left: int, top: int, width: int, height: int}>}>
     */
    private function keepLargest(array $paragraphs): array
    {
        $method = new \ReflectionMethod(DiagramTranslatorService::class, 'keepLargestText');

        return $method->invoke(new DiagramTranslatorService, $paragraphs);
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

    /**
     * Уже переведённую картинку трогать нельзя.
     *
     * Массовый прогон по архиву показал, во что это обходится: часть обложек
     * была русской, английская модель прочитала кириллицу как латиницу
     * («Топ-16 обязательных ресурсов» → «Ton-16 pecypcosB»), перевод мусора
     * лёг поверх нормального текста. Файл перезаписывается на месте — без
     * бэкапа это потеря навсегда.
     */
    #[DataProvider('alreadyRussian')]
    public function test_russian_text_is_not_translated_again(string $text): void
    {
        $this->assertTrue($this->isAlreadyTranslated($text), "«{$text}» уже по-русски, переводить нельзя");
    }

    #[DataProvider('stillEnglish')]
    public function test_english_text_is_still_translated(string $text): void
    {
        $this->assertFalse($this->isAlreadyTranslated($text), "«{$text}» по-английски, перевод нужен");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function alreadyRussian(): array
    {
        return [
            'чистый русский' => ['Лучшие практики загрузки файлов'],
            'русский с терминами' => ['Топ-16 обязательных ресурсов для продвинутой разработки PHP (Laravel и Symfony)'],
            'русский с числами' => ['Ошибки PHP № 11-20 и как их чинить'],
            // Худший реалистичный случай: русских слов мало, латинских
            // терминов много. По буквам доля тут 0.13 — ниже любого разумного
            // порога, и картинку бы испортило. По словам 2 из 9.
            'техзаголовок с обилием терминов' => ['Разбор N+1 в Eloquent: Laravel Debugbar, Telescope, Clockwork, Xdebug'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stillEnglish(): array
    {
        return [
            'чистый английский' => ['File Upload Best Practices in Laravel'],
            'английский с подписью' => ['Software Solutions DEV Jul 27'],
            // Одиночная кириллическая буква вместо похожей латинской — обычная
            // ошибка OCR, из-за неё вся обложка не должна остаться без перевода.
            'английский с ошибкой распознавания' => ['Realtime Аpps with Laravel Reverb and WebSockets'],
            // Короткая метка диаграммы: «CPU» с подменёнными С и Р даёт долю
            // кириллицы 1.0, но судить по трём буквам нельзя.
            'короткая метка с гомоглифами' => ['СРU'],
            'пусто' => [''],
            'только цифры и знаки' => ['12345 -- ++ ###'],
        ];
    }

    /**
     * Сквозной инвариант, ради которого делалась защита: уже русская картинка
     * выходит из сервиса побайтово той же.
     *
     * Заодно единственный тест, который заметит откат `-l eng+rus` обратно на
     * `-l eng`: одной английской моделью кириллица читается как латиница, текст
     * не опознаётся русским и картинка уходит в перевод.
     */
    public function test_already_russian_image_is_left_untouched(): void
    {
        if (! $this->tesseractAvailable()) {
            $this->markTestSkipped('tesseract бинарник недоступен в этом окружении');
        }

        $path = $this->makeMultilineImage(['Лучшие практики загрузки', 'файлов в Laravel']);
        $before = file_get_contents($path);

        $service = new DiagramTranslatorService($this->fakeTranslator('Что угодно другое'));

        $this->assertFalse($service->translate($path, headingOnly: true));
        $this->assertSame($before, file_get_contents($path), 'русская картинка была перезаписана');

        @unlink($path);
    }

    private function isAlreadyTranslated(string $text): bool
    {
        $method = new \ReflectionMethod(DiagramTranslatorService::class, 'looksAlreadyTranslated');

        return $method->invoke(new DiagramTranslatorService, $text);
    }

    private function makeMultilineImage(array $lines, bool $centered = false): string
    {
        $image = imagecreatetruecolor(560, 160);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, 560, 160, $white);

        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $y = 60;

        foreach ($lines as $line) {
            $size = $centered ? 20 : 26;
            $bbox = imagettfbbox($size, 0, $font, $line);
            $x = $centered ? (int) ((560 - abs($bbox[4] - $bbox[0])) / 2) : 30;

            // Настоящий TTF, а не imagestring: встроенный растровый шрифт GD
            // Tesseract распознаёт неуверенно, и строки отсеивались бы по
            // MIN_CONFIDENCE ещё до группировки.
            imagettftext($image, $size, 0, $x, $y, $black, $font, $line);
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

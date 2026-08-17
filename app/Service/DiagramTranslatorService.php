<?php

namespace App\Service;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Stichoza\GoogleTranslate\GoogleTranslate;

/**
 * Переводит текст, нарисованный внутри картинки (диаграммы, скриншоты, инфографика,
 * обложки статей): распознаёт слова с координатами через Tesseract OCR, собирает их
 * в строки и параграфы, переводит параграф целиком, закрашивает исходный текст
 * цветом фона и раскладывает перевод обратно по строкам оригинала. Работает с
 * произвольной картинкой независимо от расположения/цвета фона — в отличие от
 * прежнего ImageTranslatorService, который требовал найти отдельную светлую область
 * под текст и не работал на сплошных цветных обложках.
 *
 * Единица перевода — параграф, а не строка: фраза, разорванная переносом, теряла
 * контекст, и «The Easiest Way to Look Up / GeoIP in Laravel» превращалось в
 * «Самый простой способ посмотреть вверх».
 */
class DiagramTranslatorService
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    private const MIN_CONFIDENCE = 40;

    private const PADDING = 4;

    /**
     * Потолок на распознавание одной картинки.
     *
     * Дефолтные 60 секунд Illuminate\Process опасны рядом с StorePostJob: у
     * задачи свой лимит 420 секунд на всю статью, а картинок в ней бывает до
     * сорока. Десять секунд с запасом хватает даже на крупный скриншот.
     */
    private const OCR_TIMEOUT = 10;

    /** Ниже этого кегля перерисованный текст уже нечитаем. */
    private const MIN_FONT_SIZE = 8;

    /**
     * Переводчик можно подменить явно (тесты); по умолчанию null — сервис
     * сам создаст правильно настроенный (target=ru + прокси) экземпляр.
     *
     * Параметр НАРОЧНО без типа GoogleTranslate: при method injection
     * (handle(DiagramTranslatorService $service) в команде) Laravel всё
     * равно попытался бы построить GoogleTranslate через контейнер — у неё
     * все параметры конструктора со значениями по умолчанию, поэтому
     * авторезолв удаётся (target=en, без прокси) вместо null, даже если
     * тип объявлен nullable. Без типа контейнер резолвить не пытается.
     */
    public function __construct(private readonly mixed $translator = null) {}

    private function makeTranslator(): GoogleTranslate
    {
        if ($this->translator instanceof GoogleTranslate) {
            return $this->translator;
        }

        $translator = new GoogleTranslate('ru');
        if ($proxy = config('releases.curl_proxy')) {
            $translator->setOptions(['proxy' => 'socks5://'.$proxy]);
        }

        return $translator;
    }

    /**
     * Переводит текст на картинке и сохраняет результат по тому же пути.
     * Возвращает true, если хотя бы одна строка была переведена и перерисована.
     */
    public function translate(string $imagePath): bool
    {
        try {
            $lines = $this->detectTextLines($imagePath);

            // null — OCR не отработал (нет бинаря, ошибка запуска), [] — текста
            // на картинке нет. Разница принципиальна: первое чинится
            // администратором, второе нормально. Пока оба случая сводились к
            // «no text detected», прод три недели молча не переводил ни одной
            // картинки — 124 записи в логе и ни одной перерисовки.
            if ($lines === null) {
                return false;
            }

            if ($lines === []) {
                Log::info('DiagramTranslator: no text detected', ['path' => $imagePath]);

                return false;
            }

            $image = $this->loadImage($imagePath);
            if (! $image) {
                Log::warning('DiagramTranslator: cannot load image', ['path' => $imagePath]);

                return false;
            }

            $translator = $this->makeTranslator();
            $redrawn = 0;

            foreach ($lines as $paragraph) {
                $translated = $this->translateLine($translator, $paragraph['text']);
                if ($translated === null || mb_strtolower(trim($translated)) === mb_strtolower(trim($paragraph['text']))) {
                    continue;
                }

                $redrawn += $this->redrawParagraph($image, $paragraph['lines'], $translated) ? 1 : 0;
            }

            if ($redrawn === 0) {
                imagedestroy($image);

                return false;
            }

            $this->saveImage($image, $imagePath);
            imagedestroy($image);

            // Считаем параграфы, а не строки: именно по этому логу разбирали
            // прошлый инцидент, и имена полей не должны врать о единице счёта.
            Log::info('DiagramTranslator: done', ['path' => $imagePath, 'paragraphs_total' => count($lines), 'paragraphs_redrawn' => $redrawn]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('DiagramTranslator: failed', ['path' => $imagePath, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Распознаёт слова через Tesseract (TSV с координатами), группирует их в строки
     * по (block_num, par_num, line_num) и считает общий bounding box строки.
     *
     * --psm 3 (полная автосегментация страницы, дефолт Tesseract) — а не 6
     * (один блок текста): на диаграммах с колонками (узкая цветная метка
     * слева + широкий текст справа) psm 6 путает их и сливает метку с первой
     * строкой контента в одну "строку" по Y-координате, игнорируя разрыв по
     * X. psm 3 определяет их как разные block_num — метка и контент
     * распознаются раздельно. Заодно у psm 3 оказалась выше точность на
     * коротких метках (воспроизведено: "WORKING" на синем фоне читалось как
     * "WAL}" с psm 6, но верно распознавалось с psm 3)
     *
     * Возвращает null, если распознавание не выполнялось: это не то же самое,
     * что пустой массив («текста нет»).
     *
     * @return array<int, array{text: string, lines: array<int, array{left: int, top: int, width: int, height: int}>}>|null
     */
    private function detectTextLines(string $imagePath): ?array
    {
        $tsv = $this->runOcr($imagePath);

        if ($tsv === null) {
            return null;
        }

        if (trim($tsv) === '') {
            return [];
        }

        $rows = array_filter(explode("\n", trim($tsv)));
        array_shift($rows); // заголовок TSV

        $grouped = [];
        foreach ($rows as $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12) {
                continue;
            }

            [$level, $page, $block, $par, $lineNum, $word, $left, $top, $width, $height, $conf, $text] = $cols;

            $text = trim($text);
            if ($text === '' || (float) $conf < self::MIN_CONFIDENCE) {
                continue;
            }

            $key = "{$block}.{$par}.{$lineNum}";
            $grouped[$key]['words'][] = $text;
            $grouped[$key]['left'] = min($grouped[$key]['left'] ?? PHP_INT_MAX, (int) $left);
            $grouped[$key]['top'] = min($grouped[$key]['top'] ?? PHP_INT_MAX, (int) $top);
            $grouped[$key]['right'] = max($grouped[$key]['right'] ?? 0, (int) $left + (int) $width);
            $grouped[$key]['bottom'] = max($grouped[$key]['bottom'] ?? 0, (int) $top + (int) $height);
        }

        return $this->groupIntoParagraphs($grouped);
    }

    /**
     * Собирает распознанные строки в параграфы — по block и par, отбрасывая
     * номер строки.
     *
     * Раньше единицей перевода была строка, и фраза, разорванная переносом,
     * уходила в переводчик половинками. На обложке dev.to «The Easiest Way to
     * Look Up» / «GeoIP in Laravel» первая половина заканчивалась висящим
     * фразовым глаголом, и получалось «Самый простой способ посмотреть вверх»
     * вместо «найти». Целый параграф даёт переводчику контекст — заодно это
     * один запрос вместо N, что для скрейпера Google Translate без
     * rate-limit'а тоже небезразлично.
     *
     * @param  array<string, array{words: array<int, string>, left: int, top: int, right: int, bottom: int}>  $lines
     * @return array<int, array{text: string, lines: array<int, array{left: int, top: int, width: int, height: int}>}>
     */
    private function groupIntoParagraphs(array $lines): array
    {
        $paragraphs = [];

        foreach ($lines as $key => $group) {
            $text = implode(' ', $group['words']);

            // Отсев идёт по строке, а не по готовому параграфу: логотип и
            // домен занимают свою строку, но попадают в общий block.par с
            // соседним текстом. Проверив только склейку, мы бы нашли в ней
            // осмысленные слова, перевели всё вместе и закрасили бокс строки
            // с логотипом — то есть ровно то, от чего эти проверки защищают.
            if ($this->looksLikeDomain($text) || $this->looksLikeGlyphNoise($text)) {
                continue;
            }

            // Ключ строки — "block.par.line"; параграф — всё, кроме последнего.
            $paragraphKey = implode('.', array_slice(explode('.', $key), 0, 2));

            $paragraphs[$paragraphKey][] = [
                'text' => $text,
                'left' => $group['left'],
                'top' => $group['top'],
                'width' => $group['right'] - $group['left'],
                'height' => $group['bottom'] - $group['top'],
            ];
        }

        return array_values(array_map(function (array $rows): array {
            // Порядок строк сверху вниз — на нём держится вся раскладка
            // перевода. Tesseract печатает слова в порядке чтения, но это
            // свойство его вывода, а не гарантия структуры: перестановка
            // строк дала бы не падение, а тихо перепутанный текст.
            usort($rows, static fn (array $a, array $b): int => $a['top'] <=> $b['top']);

            return [
                'text' => implode(' ', array_column($rows, 'text')),
                'lines' => array_map(static fn (array $row): array => [
                    'left' => $row['left'],
                    'top' => $row['top'],
                    'width' => $row['width'],
                    'height' => $row['height'],
                ], $rows),
            ];
        }, $paragraphs));
    }

    /**
     * Запускает Tesseract и отдаёт TSV, либо null, если запустить не удалось.
     *
     * proc_open со списком аргументов, а не строка в shell_exec: shell_exec
     * возвращал одно и то же пустое значение и когда текста нет, и когда
     * бинаря нет, а `2>/dev/null` дописанный к команде глушил ровно то
     * сообщение, которое объясняло причину («command not found»). Ошибка
     * маскировалась под штатный результат — на проде Tesseract не был
     * установлен вовсе, и это выяснилось только через ручной разбор.
     *
     * Побочно снимается вопрос экранирования: без шелла имя файла не может
     * быть истолковано как часть команды.
     */
    private function runOcr(string $imagePath): ?string
    {
        $binary = (string) config('releases.ocr_binary', 'tesseract');

        // Illuminate\Process, а не proc_open вручную: Symfony Process под ним
        // читает stdout и stderr одновременно. При ручном чтении «сначала весь
        // stdout, потом stderr» процесс, заполнивший буфер stderr, встал бы
        // намертво, а вместе с ним и воркер очереди.
        //
        // Аргументы списком — команда не проходит через шелл, поэтому имя файла
        // не может быть истолковано как её часть.
        $result = Process::timeout(self::OCR_TIMEOUT)
            ->run([$binary, $imagePath, 'stdout', '--psm', '3', '-l', 'eng', 'tsv']);

        if ($result->failed()) {
            Log::warning('DiagramTranslator: OCR не отработал — текст на картинках не переводится', [
                'binary' => $binary,
                'exit_code' => $result->exitCode(),
                'stderr' => mb_substr(trim($result->errorOutput()), 0, 500),
                // Голое имя ищется в PATH процесса PHP, а не логин-шелла:
                // у воркера под supervisor он легко оказывается урезанным, и
                // тогда «бинарь не найден» означает не «не установлен».
                'path' => getenv('PATH'),
                'hint' => $result->exitCode() === 127
                    ? 'бинарь не найден: ./vendor/bin/envoy run ocr-install'
                    : null,
            ]);

            return null;
        }

        return $result->output();
    }

    private function translateLine(GoogleTranslate $translator, string $text): ?string
    {
        // Бренд/домен в духе "stitcher.io" или "dev.to" — не название, а
        // технический токен; Google Translate переводит такие строки
        // буквально ("stitcher" → "шитьё") и портит узнаваемость бренда.
        if ($this->looksLikeDomain($text)) {
            return null;
        }

        if ($this->looksLikeGlyphNoise($text)) {
            return null;
        }

        try {
            $translated = $translator->translate($text);

            return trim((string) $translated) !== '' ? $translated : null;
        } catch (\Throwable $e) {
            Log::warning('DiagramTranslator: line translate failed', ['text' => $text, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Распознанное — не текст, а разобранный на буквы логотип или иконка.
     *
     * Tesseract читает как текст всё, что похоже на буквы: логотип DEV в
     * чёрном квадрате приходит строкой «o m <», значок автора — «®». Раньше
     * такие фрагменты переводились и перерисовывались, то есть сервис
     * аккуратно замазывал чужой логотип и писал поверх «ом<».
     *
     * Признак — отсутствие хотя бы одного настоящего слова: три буквы подряд.
     * Осмысленная подпись на диаграмме их почти всегда содержит, набор
     * обрывков глифов — нет. Заодно отсеиваются «CI/CD», «N+1», «5 ms», «x86»
     * и прочие технические токены: переводить в них нечего.
     *
     * Проверка ловит логотип ровно потому, что OCR разбирает его на глифы.
     * Логотип, прочитанный как настоящее слово, отличить нечем — «DEV» здесь
     * пройдёт как обычный текст. Спасает следующая ступень: перевод, совпавший
     * с оригиналом, не перерисовывается.
     */
    private function looksLikeGlyphNoise(string $text): bool
    {
        return preg_match('/\p{L}{3,}/u', $text) !== 1;
    }

    /**
     * Строка целиком выглядит как домен (например, "stitcher.io", "dev.to") —
     * без пробелов, из точечных сегментов вида буквы/цифры/дефис.
     */
    private function looksLikeDomain(string $text): bool
    {
        return (bool) preg_match(
            '/^[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)+$/i',
            trim($text)
        );
    }

    /**
     * Раскладывает перевод параграфа обратно по строкам оригинала.
     *
     * Закрашиваем и рисуем построчно, а не одним прямоугольником на весь
     * параграф: цвет фона берётся из полосы над каждой строкой, и на градиенте
     * или цветной плашке единая заливка по объединённому боксу дала бы
     * заметную заплату.
     *
     * Размер шрифта общий на параграф — иначе строки одной фразы вышли бы
     * разного кегля.
     */
    private function redrawParagraph(\GdImage $image, array $lines, string $text): bool
    {
        $layout = $this->layoutParagraph($text, $lines);

        if ($layout === null) {
            // Однострочную подпись рисуем как раньше — с автоподбором кегля до
            // минимума и правом чуть вылезти за бокс. Иначе короткие метки
            // диаграмм («Home» → «Домашняя страница») перестали бы
            // переводиться вовсе: перевод длиннее оригинала почти всегда, а
            // раскладывать его тут некуда.
            if (count($lines) === 1) {
                $color = $this->eraseLine($image, $lines[0]);
                $this->drawLineText($image, $lines[0], $text, $color, null);

                return true;
            }

            // Многострочный параграф — другое дело: обрезать половину фразы
            // хуже, чем оставить её непереведённой.
            Log::info('DiagramTranslator: перевод не помещается в исходные строки, параграф оставлен как есть', [
                'text' => mb_substr($text, 0, 120),
                'lines' => count($lines),
            ]);

            return false;
        }

        // Два прохода, а не один: бокс строки берётся с запасом PADDING сверху
        // и снизу, и при плотной интерлиньяже (в заголовках это норма) он
        // перекрывает соседний. Закрашивая и рисуя в одном цикле, заливка
        // следующей строки съедала бы нижние выносные элементы уже
        // нарисованной предыдущей — «р», «у», «д».
        $colors = [];

        foreach ($lines as $index => $line) {
            $colors[$index] = $this->eraseLine($image, $line);
        }

        foreach ($lines as $index => $line) {
            $row = $layout['rows'][$index] ?? '';

            if (trim($row) === '') {
                continue;
            }

            $this->drawLineText($image, $line, $row, $colors[$index], $layout['font_size']);
        }

        return true;
    }

    /**
     * Подбирает кегль и разбивает перевод на столько строк, сколько было в
     * оригинале, укладываясь в ширину каждой.
     *
     * Русский текст длиннее английского примерно на пятнадцать процентов,
     * поэтому кегль почти всегда приходится уменьшать — начинаем с высоты
     * первой строки и спускаемся до предела читаемости.
     *
     * @param  array<int, array{left: int, top: int, width: int, height: int}>  $lines
     * @return array{font_size: int, rows: array<int, string>}|null
     */
    private function layoutParagraph(string $text, array $lines): ?array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === [] || $lines === []) {
            return null;
        }

        // Кегль общий на параграф, поэтому считается от САМОЙ НИЗКОЙ строки, а
        // не от первой: строка с выносными элементами («Way to Look Up») выше
        // соседней без них, и размер, подобранный по ней, не влез бы в бокс
        // второй — перевод налез бы на соседние строки сверху и снизу.
        $lowest = min(array_column($lines, 'height'));
        $startSize = max(self::MIN_FONT_SIZE, min(28, (int) ($lowest * 0.9)));

        for ($fontSize = $startSize; $fontSize >= self::MIN_FONT_SIZE; $fontSize--) {
            $rows = $this->wrapWords($words, $lines, $fontSize);

            if ($rows !== null) {
                return ['font_size' => $fontSize, 'rows' => $rows];
            }
        }

        return null;
    }

    /**
     * Жадно раскладывает слова по строкам: в каждую берём, пока помещается по
     * ширине. null — не уложилось (слова кончились не все либо одно слово шире
     * своей строки), значит кегль нужно уменьшить.
     *
     * @param  array<int, string>  $words
     * @param  array<int, array{left: int, top: int, width: int, height: int}>  $lines
     * @return array<int, string>|null
     */
    private function wrapWords(array $words, array $lines, int $fontSize): ?array
    {
        $rows = [];
        $index = 0;
        $total = count($words);

        foreach ($lines as $line) {
            $available = $line['width'] + self::PADDING * 2;
            $current = '';

            while ($index < $total) {
                $candidate = $current === '' ? $words[$index] : $current.' '.$words[$index];

                if ($this->textWidth($candidate, $fontSize) > $available) {
                    break;
                }

                $current = $candidate;
                $index++;
            }

            if ($current === '' && $index < $total) {
                // Даже одно слово не влезает в эту строку — кегль велик.
                return null;
            }

            $rows[] = $current;
        }

        return $index >= $total ? $rows : null;
    }

    private function textWidth(string $text, int $fontSize): int
    {
        if ($text === '') {
            return 0;
        }

        $bbox = imagettfbbox($fontSize, 0, self::FONT, $text);

        return (int) abs($bbox[4] - $bbox[0]);
    }

    /**
     * Закрашивает исходную строку цветом фона (самый частый цвет в узкой полосе
     * сразу над строкой — там текста нет) и рисует перевод тем же по центру
     * бокса, автоматически подбирая размер шрифта и контрастный цвет текста.
     */
    /**
     * Стирает исходную строку, заливая её боксом цвета фона, и возвращает этот
     * цвет — он же нужен, чтобы подобрать контрастный цвет текста поверх.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function eraseLine(\GdImage $image, array $line): array
    {
        $bgColor = $this->detectBackgroundColor($image, $line);
        [$bgR, $bgG, $bgB] = $bgColor;
        $fill = imagecolorallocate($image, $bgR, $bgG, $bgB);

        [$left, $top, $width, $height] = $this->paddedBox($line);
        imagefilledrectangle($image, $left, $top, $left + $width, $top + $height, $fill);

        return $bgColor;
    }

    /**
     * Рисует строку перевода в уже очищенном боксе.
     *
     * $fontSize = null означает «подбери сам» — так рисуются одиночные подписи,
     * где раскладывать нечего.
     *
     * @param  array{0: int, 1: int, 2: int}  $bgColor
     */
    private function drawLineText(\GdImage $image, array $line, string $text, array $bgColor, ?int $fontSize): void
    {
        [$left, $top, $width, $height] = $this->paddedBox($line);

        $this->drawFittedText($image, $text, $left, $top, $width, $height, $this->contrastingTextColor($bgColor), $fontSize);
    }

    /**
     * Бокс строки с запасом: OCR отдаёт координаты по глифам вплотную, и без
     * припуска у букв оставались бы недотёртые кромки.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function paddedBox(array $line): array
    {
        return [
            max(0, $line['left'] - self::PADDING),
            max(0, $line['top'] - self::PADDING),
            $line['width'] + self::PADDING * 2,
            $line['height'] + self::PADDING * 2,
        ];
    }

    /**
     * Берёт самый частый цвет в узкой полосе над строкой текста — там, скорее
     * всего, только фон, без штрихов букв.
     */
    private function detectBackgroundColor(\GdImage $image, array $line): array
    {
        $sampleY = max(0, $line['top'] - self::PADDING - 2);
        $counts = [];

        for ($x = $line['left']; $x < $line['left'] + $line['width']; $x += 2) {
            $rgb = imagecolorat($image, $x, $sampleY);
            $counts[$rgb] = ($counts[$rgb] ?? 0) + 1;
        }

        if (empty($counts)) {
            return [255, 255, 255];
        }

        arsort($counts);
        $rgb = array_key_first($counts);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    private function contrastingTextColor(array $bgColor): array
    {
        [$r, $g, $b] = $bgColor;
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.6 ? [26, 26, 26] : [255, 255, 255];
    }

    /**
     * Рисует текст по центру заданного бокса, уменьшая размер шрифта, пока
     * он не поместится по ширине и высоте.
     */
    private function drawFittedText(\GdImage $image, string $text, int $left, int $top, int $width, int $height, array $textColor, ?int $forcedSize = null): void
    {
        // Кегль, подобранный на весь параграф, важнее локального: строки одной
        // фразы должны быть одного размера, даже если короткая строка могла бы
        // вместить более крупный текст. Но потолок по высоте бокса действует и
        // на него — иначе строка без выносных элементов (её бокс ниже) получила
        // бы текст, налезающий на соседние сверху и снизу.
        $fontSize = $forcedSize ?? min(28, (int) ($height * 0.6));
        $fontSize = max($fontSize, self::MIN_FONT_SIZE);

        while ($fontSize > self::MIN_FONT_SIZE) {
            $bbox = imagettfbbox($fontSize, 0, self::FONT, $text);
            $textWidth = abs($bbox[4] - $bbox[0]);
            $textHeight = abs($bbox[5] - $bbox[1]);

            if ($textWidth <= $width && $textHeight <= $height) {
                break;
            }
            $fontSize--;
        }

        $bbox = imagettfbbox($fontSize, 0, self::FONT, $text);
        $textWidth = abs($bbox[4] - $bbox[0]);

        $x = $left + max(0, (int) (($width - $textWidth) / 2));
        $y = $top + (int) ($height / 2) + (int) ($fontSize / 2.8);

        [$r, $g, $b] = $textColor;
        $color = imagecolorallocate($image, $r, $g, $b);

        imagettftext($image, $fontSize, 0, $x, $y, $color, self::FONT, $text);
    }

    private function loadImage(string $path): \GdImage|false
    {
        $mime = mime_content_type($path);

        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => false,
        };
    }

    private function saveImage(\GdImage $image, string $path): void
    {
        $mime = mime_content_type($path);
        match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, 90),
            'image/png' => imagepng($image, $path),
            'image/webp' => imagewebp($image, $path, 90),
            default => imagejpeg($image, $path, 90),
        };
    }
}

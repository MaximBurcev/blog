<?php

namespace App\Service;

use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

/**
 * Переводит текст, нарисованный внутри картинки (диаграммы, скриншоты, инфографика):
 * распознаёт слова с координатами через Tesseract OCR, группирует их в строки,
 * переводит каждую строку, закрашивает исходный текст цветом фона и рисует перевод
 * на его месте. В отличие от ImageTranslatorService (который просто дописывает
 * переведённый заголовок в свободную область обложки), эта работает с произвольной
 * картинкой и множеством текстовых блоков внутри неё.
 */
class DiagramTranslatorService
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    private const MIN_CONFIDENCE = 40;

    private const PADDING = 4;

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
            if (empty($lines)) {
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

            foreach ($lines as $line) {
                $translated = $this->translateLine($translator, $line['text']);
                if ($translated === null || mb_strtolower(trim($translated)) === mb_strtolower(trim($line['text']))) {
                    continue;
                }

                $this->redrawLine($image, $line, $translated);
                $redrawn++;
            }

            if ($redrawn === 0) {
                imagedestroy($image);

                return false;
            }

            $this->saveImage($image, $imagePath);
            imagedestroy($image);

            Log::info('DiagramTranslator: done', ['path' => $imagePath, 'lines_total' => count($lines), 'lines_redrawn' => $redrawn]);

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
     * @return array<int, array{text: string, left: int, top: int, width: int, height: int}>
     */
    private function detectTextLines(string $imagePath): array
    {
        $tsv = shell_exec(
            'tesseract '.escapeshellarg($imagePath).' stdout --psm 3 -l eng tsv 2>/dev/null'
        );

        if (empty($tsv)) {
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

        $lines = [];
        foreach ($grouped as $group) {
            $lines[] = [
                'text' => implode(' ', $group['words']),
                'left' => $group['left'],
                'top' => $group['top'],
                'width' => $group['right'] - $group['left'],
                'height' => $group['bottom'] - $group['top'],
            ];
        }

        return $lines;
    }

    private function translateLine(GoogleTranslate $translator, string $text): ?string
    {
        try {
            $translated = $translator->translate($text);

            return trim((string) $translated) !== '' ? $translated : null;
        } catch (\Throwable $e) {
            Log::warning('DiagramTranslator: line translate failed', ['text' => $text, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Закрашивает исходную строку цветом фона (самый частый цвет в узкой полосе
     * сразу над строкой — там текста нет) и рисует перевод тем же по центру
     * бокса, автоматически подбирая размер шрифта и контрастный цвет текста.
     */
    private function redrawLine(\GdImage $image, array $line, string $text): void
    {
        $left = max(0, $line['left'] - self::PADDING);
        $top = max(0, $line['top'] - self::PADDING);
        $width = $line['width'] + self::PADDING * 2;
        $height = $line['height'] + self::PADDING * 2;

        $bgColor = $this->detectBackgroundColor($image, $line);
        [$bgR, $bgG, $bgB] = $bgColor;
        $fill = imagecolorallocate($image, $bgR, $bgG, $bgB);
        imagefilledrectangle($image, $left, $top, $left + $width, $top + $height, $fill);

        $textColor = $this->contrastingTextColor($bgColor);
        $this->drawFittedText($image, $text, $left, $top, $width, $height, $textColor);
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
    private function drawFittedText(\GdImage $image, string $text, int $left, int $top, int $width, int $height, array $textColor): void
    {
        $fontSize = min(28, (int) ($height * 0.6));
        $fontSize = max($fontSize, 8);

        while ($fontSize > 8) {
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

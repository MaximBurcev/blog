<?php

namespace App\Service;

use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickDraw;
use ImagickPixel;

class ImageTranslatorService
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const TEXT_COLOR = '#1a1a1a';
    private const PADDING = 40;

    /**
     * Определяет светлую (белую) область слева, закрашивает её белым
     * и рисует поверх переведённый заголовок.
     * Возвращает путь к изменённому файлу.
     */
    public function translateCoverImage(string $imagePath, string $translatedTitle): string
    {
        try {
            $imagick = new Imagick($imagePath);
            $width   = $imagick->getImageWidth();
            $height  = $imagick->getImageHeight();

            $lightWidth = $this->detectLightRegionWidth($imagick, $width, $height);

            if ($lightWidth < $width * 0.2) {
                Log::info('ImageTranslator: no significant light region, skipping', ['path' => $imagePath]);
                $imagick->destroy();
                return $imagePath;
            }

            // Закрашиваем белую область белым прямоугольником
            $draw = new ImagickDraw();
            $draw->setFillColor(new ImagickPixel('white'));
            $draw->rectangle(0, 0, $lightWidth, $height);
            $imagick->drawImage($draw);

            // Рисуем переведённый текст
            $this->drawText($imagick, $translatedTitle, $lightWidth, $height);

            $imagick->writeImage($imagePath);
            $imagick->destroy();

            Log::info('ImageTranslator: done', ['path' => $imagePath, 'light_width' => $lightWidth]);
        } catch (\Exception $e) {
            Log::warning('ImageTranslator: failed', ['error' => $e->getMessage()]);
        }

        return $imagePath;
    }

    /**
     * Сканирует пиксели по нескольким горизонтальным строкам (25%, 50%, 75% высоты),
     * находит максимальную ширину светлой (яркость > 200) области.
     */
    private function detectLightRegionWidth(Imagick $imagick, int $width, int $height): int
    {
        $scanRows = [
            (int) ($height * 0.25),
            (int) ($height * 0.50),
            (int) ($height * 0.75),
        ];

        $maxLightWidth = 0;

        foreach ($scanRows as $y) {
            $lightWidth = 0;
            for ($x = 0; $x < $width; $x++) {
                $pixel = $imagick->getImagePixelColor($x, $y);
                $color = $pixel->getColor();
                $brightness = ($color['r'] + $color['g'] + $color['b']) / 3;

                if ($brightness < 200) {
                    break;
                }
                $lightWidth = $x;
            }
            if ($lightWidth > $maxLightWidth) {
                $maxLightWidth = $lightWidth;
            }
        }

        return $maxLightWidth;
    }

    /**
     * Рисует текст в белой области, автоматически подбирая размер шрифта и перенося строки.
     */
    private function drawText(Imagick $imagick, string $text, int $areaWidth, int $height): void
    {
        $maxTextWidth = $areaWidth - self::PADDING * 2;

        // Подбираем размер шрифта
        $fontSize = 48;
        $lines = [];
        while ($fontSize >= 16) {
            $lines = $this->wrapText($text, $fontSize, $maxTextWidth);
            $totalHeight = count($lines) * ($fontSize * 1.3);
            if ($totalHeight <= $height - self::PADDING * 2) {
                break;
            }
            $fontSize -= 4;
        }

        $lineHeight = (int) ($fontSize * 1.3);
        $totalTextHeight = count($lines) * $lineHeight;
        $startY = (int) (($height - $totalTextHeight) / 2) + $fontSize;

        $draw = new ImagickDraw();
        $draw->setFont(self::FONT);
        $draw->setFontSize($fontSize);
        $draw->setFillColor(new ImagickPixel(self::TEXT_COLOR));
        $draw->setTextAntialias(true);

        foreach ($lines as $i => $line) {
            $y = $startY + $i * $lineHeight;
            $draw->annotation(self::PADDING, $y, $line);
        }

        $imagick->drawImage($draw);
    }

    /**
     * Разбивает текст на строки по ширине с заданным размером шрифта.
     */
    private function wrapText(string $text, int $fontSize, int $maxWidth): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        $imagick = new Imagick();
        $draw = new ImagickDraw();
        $draw->setFont(self::FONT);
        $draw->setFontSize($fontSize);

        foreach ($words as $word) {
            $test = $current ? $current . ' ' . $word : $word;
            $metrics = $imagick->queryFontMetrics($draw, $test);

            if ($metrics['textWidth'] > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }
        }

        if ($current) {
            $lines[] = $current;
        }

        $imagick->destroy();
        return $lines;
    }
}

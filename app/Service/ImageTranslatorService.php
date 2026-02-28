<?php

namespace App\Service;

use Illuminate\Support\Facades\Log;

class ImageTranslatorService
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const TEXT_COLOR = [26, 26, 26]; // #1a1a1a
    private const PADDING = 40;

    /**
     * Определяет светлую (белую) область слева, закрашивает её белым
     * и рисует поверх переведённый заголовок.
     * Возвращает путь к изменённому файлу.
     */
    public function translateCoverImage(string $imagePath, string $translatedTitle): string
    {
        try {
            $image = $this->loadImage($imagePath);
            if (!$image) {
                Log::warning('ImageTranslator: cannot load image', ['path' => $imagePath]);
                return $imagePath;
            }

            $width  = imagesx($image);
            $height = imagesy($image);

            $lightWidth = $this->detectLightRegionWidth($image, $width, $height);

            if ($lightWidth < $width * 0.2) {
                Log::info('ImageTranslator: no significant light region, skipping', ['path' => $imagePath]);
                imagedestroy($image);
                return $imagePath;
            }

            // Закрашиваем светлую область белым прямоугольником
            $white = imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, 0, 0, $lightWidth, $height, $white);

            // Рисуем переведённый текст
            $this->drawText($image, $translatedTitle, $lightWidth, $height);

            $this->saveImage($image, $imagePath);
            imagedestroy($image);

            Log::info('ImageTranslator: done', ['path' => $imagePath, 'light_width' => $lightWidth]);
        } catch (\Throwable $e) {
            Log::warning('ImageTranslator: failed', ['error' => $e->getMessage()]);
        }

        return $imagePath;
    }

    private function loadImage(string $path): \GdImage|false
    {
        $mime = mime_content_type($path);
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png'  => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default      => false,
        };
    }

    private function saveImage(\GdImage $image, string $path): void
    {
        $mime = mime_content_type($path);
        match ($mime) {
            'image/jpeg' => imagejpeg($image, $path, 90),
            'image/png'  => imagepng($image, $path),
            'image/webp' => imagewebp($image, $path, 90),
            default      => imagejpeg($image, $path, 90),
        };
    }

    /**
     * Сканирует пиксели по нескольким горизонтальным строкам (25%, 50%, 75% высоты),
     * находит максимальную ширину светлой (яркость > 200) области.
     */
    private function detectLightRegionWidth(\GdImage $image, int $width, int $height): int
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
                $rgb        = imagecolorat($image, $x, $y);
                $r          = ($rgb >> 16) & 0xFF;
                $g          = ($rgb >> 8) & 0xFF;
                $b          = $rgb & 0xFF;
                $brightness = ($r + $g + $b) / 3;

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
    private function drawText(\GdImage $image, string $text, int $areaWidth, int $height): void
    {
        $maxTextWidth = $areaWidth - self::PADDING * 2;

        $fontSize = 48;
        $lines    = [];
        while ($fontSize >= 16) {
            $lines       = $this->wrapText($text, $fontSize, $maxTextWidth);
            $totalHeight = count($lines) * ($fontSize * 1.3);
            if ($totalHeight <= $height - self::PADDING * 2) {
                break;
            }
            $fontSize -= 4;
        }

        $lineHeight      = (int) ($fontSize * 1.3);
        $totalTextHeight = count($lines) * $lineHeight;
        $startY          = (int) (($height - $totalTextHeight) / 2) + $fontSize;

        [$r, $g, $b] = self::TEXT_COLOR;
        $color = imagecolorallocate($image, $r, $g, $b);

        foreach ($lines as $i => $line) {
            $y = $startY + $i * $lineHeight;
            imagettftext($image, $fontSize, 0, self::PADDING, $y, $color, self::FONT, $line);
        }
    }

    /**
     * Разбивает текст на строки по ширине с заданным размером шрифта.
     */
    private function wrapText(string $text, int $fontSize, int $maxWidth): array
    {
        $words   = explode(' ', $text);
        $lines   = [];
        $current = '';

        foreach ($words as $word) {
            $test    = $current ? $current . ' ' . $word : $word;
            $bbox    = imagettfbbox($fontSize, 0, self::FONT, $test);
            $textWidth = abs($bbox[4] - $bbox[0]);

            if ($textWidth > $maxWidth && $current !== '') {
                $lines[]  = $current;
                $current  = $word;
            } else {
                $current = $test;
            }
        }

        if ($current) {
            $lines[] = $current;
        }

        return $lines;
    }
}

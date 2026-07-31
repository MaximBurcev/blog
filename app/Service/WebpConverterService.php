<?php

namespace App\Service;

use Illuminate\Support\Facades\Log;

/**
 * Перекодирование скачанных картинок в WebP.
 *
 * Источники отдают обложки статей PNG'ами по 150-300 КБ, а иногда и
 * почти несжатым WebP по полмегабайта (SEO-аудит 2026-07-30). Формат
 * понимают все актуальные браузеры, поэтому храним одну версию вместо
 * <picture> с фолбэком.
 *
 * Работаем через GD: на проде нет Imagick, а тянуть ради этого отдельный
 * пакет обработки изображений незачем.
 */
class WebpConverterService
{
    private const QUALITY = 82;

    /**
     * Длинная сторона, до которой уменьшаем. Обложка показывается максимум
     * во всю ширину контентной колонки (~1200 px на десктопе), в листингах
     * — 370 px; всё, что крупнее, — чистый вес.
     */
    private const MAX_SIDE = 1600;

    /**
     * Верхняя граница по числу пикселей на входе. GD распаковывает картинку
     * в память целиком (~4 байта на пиксель), а прод — машина на 1.8 ГБ,
     * которая уже однажды ушла в OOM. 40 Мп ≈ 160 МБ на кадр.
     */
    private const MAX_INPUT_PIXELS = 40_000_000;

    /**
     * Результат принимаем, только если он ощутимо легче исходника — иначе
     * незачем терять качество на повторном кодировании.
     */
    private const MIN_GAIN = 0.9;

    /**
     * Переводит бинарь картинки в WebP, попутно уменьшая слишком крупные
     * кадры. Возвращает null, если конвертация не нужна или невозможна —
     * вызывающий код в этом случае сохраняет оригинал как есть.
     */
    public function convert(string $binary): ?string
    {
        if (! function_exists('imagewebp')) {
            return null;
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;
        $type = $info[2] ?? null;

        // GIF пропускаем: GD расплющил бы анимацию в один кадр. SVG сюда не
        // доходит вовсе — getimagesizefromstring его не распознаёт. WebP на
        // входе допускаем: источники нередко отдают его почти несжатым.
        if (! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        if ($width * $height > self::MAX_INPUT_PIXELS) {
            Log::warning('WebpConverterService: слишком большая картинка, оставляем оригинал', [
                'width' => $width,
                'height' => $height,
            ]);

            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        try {
            $image = $this->downscale($image, $width, $height);

            // PNG-обложки бывают с прозрачностью — WebP её поддерживает, но
            // альфа-канал нужно явно сохранить, иначе фон станет чёрным.
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $ok = imagewebp($image, null, self::QUALITY);
            $webp = (string) ob_get_clean();

            if (! $ok || $webp === '') {
                return null;
            }

            return strlen($webp) < strlen($binary) * self::MIN_GAIN ? $webp : null;
        } catch (\Throwable $e) {
            Log::warning('WebpConverterService: конвертация не удалась', ['error' => $e->getMessage()]);

            return null;
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * @return \GdImage уменьшенная копия либо исходное изображение
     */
    private function downscale(\GdImage $image, int $width, int $height): \GdImage
    {
        $longest = max($width, $height);

        if ($longest <= self::MAX_SIDE) {
            return $image;
        }

        $ratio = self::MAX_SIDE / $longest;
        $resized = imagescale($image, (int) round($width * $ratio), (int) round($height * $ratio));

        if ($resized === false) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }
}

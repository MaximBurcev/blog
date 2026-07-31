<?php

namespace App\Service;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Уменьшенные копии превью для srcset.
 *
 * Обложка приезжает от источника шириной 1200-1600 px и в этом виде уходит
 * во все листинги, где карточка занимает 370 px, блок «Популярные посты» —
 * 80 px, а related-посты — 270 px. PageSpeed справедливо ругался: «размер
 * изображения (1200x805) превышает размер контейнера (382x256)».
 *
 * Варианты кладём рядом с оригиналом под именем «{имя}-{ширина}.webp».
 * Отдельной таблицы нет намеренно: набор ширин — деталь вёрстки, а не
 * данные, и на диске он самоописателен.
 */
class ImageVariantService
{
    /**
     * Ширины под фактические размеры контейнеров: 160 — сайдбар (80 px при
     * DPR 2), 400 — карточка листинга, 800 — она же на ретине и почти вся
     * ширина экрана на мобильном.
     */
    public const WIDTHS = [160, 400, 800];

    private const QUALITY = 82;

    public function __construct(
        private readonly ?string $disk = 'public'
    ) {}

    /**
     * Создаёт недостающие варианты. Возвращает список созданных путей.
     *
     * Вариант шире оригинала не создаётся: апскейл только добавил бы вес.
     *
     * @return array<int, string>
     */
    public function generate(string $path): array
    {
        $disk = Storage::disk($this->disk);

        if (! function_exists('imagewebp') || ! $disk->exists($path)) {
            return [];
        }

        $binary = $disk->get($path);
        $info = @getimagesizefromstring($binary);

        if ($info === false) {
            return [];
        }

        [$width, $height] = $info;
        $created = [];

        foreach (self::WIDTHS as $targetWidth) {
            if ($targetWidth >= $width) {
                continue;
            }

            $variantPath = $this->variantPath($path, $targetWidth);
            if ($disk->exists($variantPath)) {
                continue;
            }

            $resized = $this->resize($binary, $width, $height, $targetWidth);
            if ($resized === null) {
                continue;
            }

            $disk->put($variantPath, $resized);
            $created[] = $variantPath;
        }

        return $created;
    }

    /**
     * Строка для атрибута srcset по уже существующим на диске вариантам.
     * Возвращает null, если вариантов нет — тогда шаблон отдаёт один src,
     * как раньше, и картинка не превращается в битую ссылку.
     */
    public function srcset(string $path): ?string
    {
        $disk = Storage::disk($this->disk);
        $parts = [];

        foreach (self::WIDTHS as $width) {
            $variantPath = $this->variantPath($path, $width);

            if ($disk->exists($variantPath)) {
                $parts[] = asset('storage/'.$variantPath).' '.$width.'w';
            }
        }

        if ($parts === []) {
            return null;
        }

        // Оригинал остаётся самым широким кандидатом: если контейнер
        // окажется больше 800 px (широкий экран, DPR 2), браузеру есть из
        // чего выбрать.
        $original = @getimagesize($disk->path($path));
        if ($original !== false) {
            $parts[] = asset('storage/'.$path).' '.$original[0].'w';
        }

        return implode(', ', $parts);
    }

    /**
     * «images/content/abc.webp» + 400 → «images/content/abc-400.webp»
     */
    public function variantPath(string $path, int $width): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = $extension === '' ? $path : substr($path, 0, -(strlen($extension) + 1));

        return $base.'-'.$width.'.webp';
    }

    private function resize(string $binary, int $width, int $height, int $targetWidth): ?string
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        try {
            $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));
            $resized = imagescale($image, $targetWidth, $targetHeight);

            if ($resized === false) {
                return null;
            }

            // Прозрачность у PNG-обложек: без явного сохранения альфы фон
            // станет чёрным.
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            ob_start();
            $ok = imagewebp($resized, null, self::QUALITY);
            $webp = (string) ob_get_clean();
            imagedestroy($resized);

            return $ok && $webp !== '' ? $webp : null;
        } catch (\Throwable $e) {
            Log::warning('ImageVariantService: не удалось уменьшить картинку', [
                'error' => $e->getMessage(),
                'width' => $targetWidth,
            ]);

            return null;
        } finally {
            imagedestroy($image);
        }
    }
}

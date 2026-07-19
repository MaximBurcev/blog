<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentImageService
{
    /**
     * Downloads images from external sources and replaces them with local paths.
     *
     * @param  string  $content
     */
    /**
     * Downloads a single image by URL and saves it to storage.
     * Returns the storage-relative path (e.g. images/content/xxx.jpg), or null on failure.
     */
    public function downloadImage(string $url): ?string
    {
        try {
            $imageContent = Http::timeout(30)->get($url)->body();

            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            $extension = $extension ?: 'jpg';
            // Strip query params from extension
            $extension = strtolower(preg_replace('/[^a-zA-Z].*/', '', $extension));
            $extension = $extension ?: 'jpg';

            $filename = 'images/content/'.Str::random(40).'.'.$extension;
            Storage::disk('public')->put($filename, $imageContent);

            Log::info('ContentImageService: downloaded', ['url' => $url, 'path' => $filename]);

            return $filename;
        } catch (\Exception $e) {
            Log::warning('ContentImageService: download failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Заменяет <picture>...</picture> с ленивой загрузкой (source srcset,
     * сам <img> без src — паттерн Medium/gitconnected для картинок в теле
     * статьи) на обычный <img src="local-path">. Без этого
     * downloadAndReplaceImages() такие картинки вообще не находит — его
     * регексп требует уже существующий src у <img>, а тут его нет,
     * реальный URL только в srcset.
     */
    public function replacePictureElements(string $content): string
    {
        $pattern = '/<picture>(.*?)<\/picture>/is';

        return preg_replace_callback($pattern, function ($matches) {
            $pictureInner = $matches[1];
            $bestUrl = $this->extractBestSrcsetUrl($pictureInner);

            if (! $bestUrl) {
                return $matches[0];
            }

            // Сохраняем ширину/высоту из исходного <img> внутри picture, чтобы не сломать вёрстку
            $sizeAttrs = '';
            if (preg_match('/<img[^>]+width="(\d+)"[^>]+height="(\d+)"/i', $pictureInner, $imgMatch)) {
                $sizeAttrs = ' width="'.$imgMatch[1].'" height="'.$imgMatch[2].'"';
            }

            try {
                $imageContent = Http::timeout(30)->get($bestUrl)->throw()->body();

                $extension = pathinfo(parse_url($bestUrl, PHP_URL_PATH), PATHINFO_EXTENSION);
                $extension = $extension ?: 'jpg';
                $extension = strtolower(preg_replace('/[^a-zA-Z].*/', '', $extension)) ?: 'jpg';

                $filename = 'images/content/'.Str::random(40).'.'.$extension;
                Storage::disk('public')->put($filename, $imageContent);
                $newImageUrl = Storage::disk('public')->url($filename);

                Log::info('ContentImageService: picture replaced', ['url' => $bestUrl, 'path' => $filename]);

                return '<img src="'.$newImageUrl.'"'.$sizeAttrs.'>';
            } catch (\Exception $e) {
                Log::warning('ContentImageService: picture download failed', ['url' => $bestUrl, 'error' => $e->getMessage()]);

                return $matches[0];
            }
        }, $content);
    }

    /**
     * Ищет лучший (максимального разрешения) URL среди <source srcset="...">
     * внутри <picture>. Предпочитает source с data-testid="og" (обычно
     * не-webp, более совместимый формат оригинала), иначе берёт первый.
     */
    private function extractBestSrcsetUrl(string $pictureInner): ?string
    {
        if (! preg_match_all('/<source\b[^>]*srcset="([^"]+)"[^>]*>/i', $pictureInner, $sourceMatches, PREG_SET_ORDER)) {
            return null;
        }

        $ogSrcset = null;
        foreach ($sourceMatches as $sourceMatch) {
            if (str_contains($sourceMatch[0], 'data-testid="og"')) {
                $ogSrcset = $sourceMatch[1];
                break;
            }
        }

        $srcset = $ogSrcset ?? $sourceMatches[0][1];

        $best = null;
        $bestWidth = -1;
        if (preg_match_all('/([^\s,]+)\s+(\d+)w/', $srcset, $entries, PREG_SET_ORDER)) {
            foreach ($entries as $entry) {
                $width = (int) $entry[2];
                if ($width > $bestWidth) {
                    $bestWidth = $width;
                    $best = $entry[1];
                }
            }
        }

        return $best;
    }

    public function downloadAndReplaceImages(string $content): string
    {
        // reg expression to match image tags
        $pattern = '/<a[^>]+href="([^"]+)"[^>]*>.*?<img[^>]+src="([^"]+)"[^>]*>.*?<\/a>/i';

        return preg_replace_callback($pattern, function ($matches) {
            $linkUrl = $matches[1];
            $imageUrl = $matches[2];

            Log::info('linkUrl', [$linkUrl]);
            Log::info('imageUrl', [$imageUrl]);

            // Skip local images
            if (! str_starts_with($imageUrl, 'http')) {
                return $matches[0];
            }

            // Download the image
            try {
                $imageContent = Http::timeout(30)->get($imageUrl)->body();

                // Generate a unique filename
                $extension = pathinfo($imageUrl, PATHINFO_EXTENSION);
                $extension = $extension ?: 'jpg';
                $filename = 'images/content/'.Str::random(40).'.'.$extension;

                // Save the image to the public storage
                Storage::disk('public')->put($filename, $imageContent);

                $newImageUrl = Storage::disk('public')->url($filename);

                Log::info('$newImageUrl', [$newImageUrl]);

                // Replace the image URL with the local path
                return '<a href="'.$newImageUrl.'"><img src="'.$newImageUrl.'"></a>';
            } catch (\Exception $e) {
                // If the download fails, leave the original URL
                Log::info('Failed to download image', [$imageUrl, $e->getMessage()]);

                return $matches[0];
            }
        }, $content);
    }
}

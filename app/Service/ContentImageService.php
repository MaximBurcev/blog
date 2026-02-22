<?php

namespace App\Service;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class ContentImageService
{
    /**
     * Downloads images from external sources and replaces them with local paths.
     *
     * @param string $content
     * @return string
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

            $filename = 'images/content/' . Str::random(40) . '.' . $extension;
            Storage::disk('public')->put($filename, $imageContent);

            Log::info('ContentImageService: downloaded', ['url' => $url, 'path' => $filename]);
            return $filename;
        } catch (\Exception $e) {
            Log::warning('ContentImageService: download failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function downloadAndReplaceImages(string $content): string
    {
        // reg expression to match image tags
        $pattern = '/<a[^>]+href="([^"]+)"[^>]*>.*?<img[^>]+src="([^"]+)"[^>]*>.*?<\/a>/i';

        return preg_replace_callback($pattern, function($matches) {
            $linkUrl = $matches[1];
            $imageUrl = $matches[2];

            Log::info('linkUrl', [$linkUrl]);
            Log::info('imageUrl', [$imageUrl]);


            // Skip local images
            if (!str_starts_with($imageUrl, 'http')) {
                return $matches[0];
            }

            // Download the image
            try {
                $imageContent = Http::timeout(30)->get($imageUrl)->body();

                // Generate a unique filename
                $extension = pathinfo($imageUrl, PATHINFO_EXTENSION);
                $extension = $extension ?: 'jpg';
                $filename = 'images/content/' . Str::random(40) . '.' . $extension;

                // Save the image to the public storage
                Storage::disk('public')->put($filename, $imageContent);

                $newImageUrl = Storage::disk('public')->url($filename);

                Log::info('$newImageUrl', [$newImageUrl]);

                // Replace the image URL with the local path
                return '<a href="' . $newImageUrl . '"><img src="' . $newImageUrl . '"></a>';
            } catch (\Exception $e) {
                // If the download fails, leave the original URL
                Log::info('Failed to download image', [$imageUrl, $e->getMessage()]);
                return $matches[0];
            }
        }, $content);
    }
}

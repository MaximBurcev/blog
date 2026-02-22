<?php

namespace App\Service;

use App\Models\Tag;
use Illuminate\Support\Facades\Log;

class TagDetectorService
{
    /**
     * Определяет массив tag_id по URL и заголовку статьи.
     * Проверяет вхождение названия тега (lower-case) в URL и заголовок.
     *
     * @return int[]
     */
    public function detect(string $title, string $url = '', string $content = ''): array
    {
        $haystack = strtolower($url . ' ' . $title . ' ' . strip_tags($content));

        $matched = [];
        foreach (Tag::all(['id', 'title']) as $tag) {
            if (str_contains($haystack, strtolower($tag->title))) {
                $matched[] = $tag->id;
            }
        }

        Log::info('TagDetector: matched', ['tag_ids' => $matched]);
        return $matched;
    }
}

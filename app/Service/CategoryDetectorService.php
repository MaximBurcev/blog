<?php

namespace App\Service;

use App\Models\Category;
use Illuminate\Support\Facades\Log;

class CategoryDetectorService
{
    /**
     * Определяет category_id по URL и заголовку статьи.
     * Проверяет вхождение названия категории (lower-case) в URL и заголовок.
     */
    public function detect(string $title, string $url = ''): ?int
    {
        $haystack = strtolower($url . ' ' . $title);

        foreach (Category::all(['id', 'title']) as $category) {
            if (str_contains($haystack, strtolower($category->title))) {
                Log::info('CategoryDetector: matched', ['category' => $category->title]);
                return $category->id;
            }
        }

        Log::info('CategoryDetector: no match found');
        return null;
    }
}

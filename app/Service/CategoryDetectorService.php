<?php

namespace App\Service;

use App\Models\Category;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CategoryDetectorService
{
    /**
     * Определяет category_id по URL, заголовку и содержимому статьи.
     * Проверяет вхождение названия категории (lower-case) в URL, заголовок
     * и текст статьи. Если ни одна существующая категория не подошла —
     * пробует словарь известных тем (config('topics.known')) и создаёт
     * категорию.
     */
    public function detect(string $title, string $url = '', string $content = ''): ?int
    {
        $haystack = strtolower($url.' '.$title.' '.strip_tags($content));

        foreach (Category::all(['id', 'title']) as $category) {
            if ($this->matchesWord($haystack, $category->title)) {
                Log::info('CategoryDetector: matched', ['category' => $category->title]);

                return $category->id;
            }
        }

        foreach (config('topics.known', []) as $keyword => $canonicalName) {
            if ($this->matchesWord($haystack, $keyword)) {
                $category = $this->findOrCreateCategory($canonicalName);
                Log::info('CategoryDetector: auto-created category', ['category' => $canonicalName]);

                return $category->id;
            }
        }

        Log::info('CategoryDetector: no match found');

        return null;
    }

    /**
     * Проверяет вхождение $needle как отдельного слова (границы \b), а не
     * произвольной подстроки. Баг: с plain str_contains() короткие названия
     * (например "AI") ложно матчились внутри "contains", "domain", "email" —
     * как только такая категория реально создавалась, следующие посты
     * массово получали её по ошибке. \b с модификатором u корректно работает
     * и с кириллицей (проверено на "Тестовая").
     */
    private function matchesWord(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/\b'.preg_quote(strtolower($needle), '/').'\b/iu', $haystack);
    }

    /**
     * Ищет категорию без учёта регистра (чтобы не плодить дубли вроде
     * "Laravel" и "laravel"), создаёт с уникальным slug при отсутствии.
     */
    private function findOrCreateCategory(string $title): Category
    {
        $existing = Category::whereRaw('LOWER(title) = ?', [mb_strtolower($title)])->first();
        if ($existing) {
            return $existing;
        }

        return Category::create([
            'title' => $title,
            'code' => Str::slug($title),
        ]);
    }
}

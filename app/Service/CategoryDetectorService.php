<?php

namespace App\Service;

use App\Models\Category;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CategoryDetectorService
{
    /**
     * Ключевые слова -> каноническое имя категории. Используется, когда ни
     * одна существующая категория не совпала по названию — позволяет
     * автоматически завести категорию под новую тему вместо того, чтобы
     * оставлять пост без категории.
     *
     * @var array<string, string>
     */
    private const KNOWN_TOPICS = [
        'laravel' => 'Laravel',
        'symfony' => 'Symfony',
        'wordpress' => 'WordPress',
        'cakephp' => 'CakePHP',
        'typo3' => 'TYPO3',
        'docker' => 'Docker',
        'kubernetes' => 'Kubernetes',
        'mysql' => 'MySQL',
        'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'javascript' => 'JavaScript',
        'node.js' => 'Node.js',
        'nodejs' => 'Node.js',
        'python' => 'Python',
        'devops' => 'DevOps',
        'security' => 'Security',
        'ai' => 'AI',
    ];

    /**
     * Определяет category_id по URL, заголовку и содержимому статьи.
     * Проверяет вхождение названия категории (lower-case) в URL, заголовок
     * и текст статьи. Если ни одна существующая категория не подошла —
     * пробует словарь известных тем (KNOWN_TOPICS) и создаёт категорию.
     */
    public function detect(string $title, string $url = '', string $content = ''): ?int
    {
        $haystack = strtolower($url.' '.$title.' '.strip_tags($content));

        foreach (Category::all(['id', 'title']) as $category) {
            if (str_contains($haystack, strtolower($category->title))) {
                Log::info('CategoryDetector: matched', ['category' => $category->title]);

                return $category->id;
            }
        }

        foreach (self::KNOWN_TOPICS as $keyword => $canonicalName) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $haystack)) {
                $category = $this->findOrCreateCategory($canonicalName);
                Log::info('CategoryDetector: auto-created category', ['category' => $canonicalName]);

                return $category->id;
            }
        }

        Log::info('CategoryDetector: no match found');

        return null;
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

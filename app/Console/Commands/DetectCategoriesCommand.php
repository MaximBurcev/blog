<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Post;
use App\Service\CategoryDetectorService;
use App\Service\LlmCategoryService;
use Illuminate\Console\Command;

class DetectCategoriesCommand extends Command
{
    protected $signature = 'posts:detect-categories
        {--limit=50 : Сколько постов без категории обработать за прогон}
        {--with-llm : Если словарь ничего не нашёл, спросить модель}';

    protected $description = 'Проставляет категорию постам без неё: словарём, а с --with-llm — ещё и через модель';

    /**
     * Отдельная команда, а не флаг posts:detect-tags: выборка другая (посты
     * без категории, а не без тегов), и запускать их хочется независимо —
     * квота Gemini на обе одна, делить её решает оператор, а не команда.
     *
     * Разбор архива идёт партиями, как и перевод черновиков: с --with-llm
     * каждый пост — это запрос к модели, а бесплатная квота Gemini — десятки
     * запросов в сутки, и делить её приходится с обычным парсингом.
     */
    public function handle(CategoryDetectorService $categoryDetector, LlmCategoryService $llmCategory): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $withLlm = (bool) $this->option('with-llm');

        $total = Post::query()->whereNull('category_id')->count();

        if ($total === 0) {
            $this->info('Постов без категории нет.');

            return self::SUCCESS;
        }

        $posts = Post::query()
            ->whereNull('category_id')
            ->oldest('created_at')
            ->limit($limit)
            ->get();

        $this->info("Постов без категории: {$total}. Партия: {$posts->count()} (лимит {$limit}). LLM: ".($withLlm ? 'да' : 'нет').'.');

        $assigned = 0;

        foreach ($posts as $post) {
            $categoryId = $categoryDetector->detect($post->title, $post->url ?? '', $post->content ?? '');
            $source = 'словарь';

            if ($categoryId === null && $withLlm) {
                $categoryId = $llmCategory->detect($post->title, $post->url ?? '', $post->content ?? '');
                $source = 'llm';
            }

            if ($categoryId === null) {
                $this->line("  #{$post->id} — не найдено: {$post->title}");

                continue;
            }

            $post->update(['category_id' => $categoryId]);
            $assigned++;

            $title = Category::find($categoryId)?->title;
            $this->line("  #{$post->id} [{$source}] {$title} — {$post->title}");
        }

        $left = Post::query()->whereNull('category_id')->count();
        $this->info("Проставлено категорий: {$assigned}. Без категории осталось: {$left}.");

        return self::SUCCESS;
    }
}

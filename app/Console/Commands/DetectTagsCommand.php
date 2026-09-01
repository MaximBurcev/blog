<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Tag;
use App\Service\LlmTaggerService;
use App\Service\TagDetectorService;
use Illuminate\Console\Command;

class DetectTagsCommand extends Command
{
    protected $signature = 'posts:detect-tags
        {--limit=50 : Сколько постов без тегов обработать за прогон}
        {--with-llm : Если словарь ничего не нашёл, спросить модель}';

    protected $description = 'Проставляет теги постам без тегов: словарём, а с --with-llm — ещё и через модель';

    /**
     * Разбор архива идёт партиями, как и перевод черновиков: с --with-llm
     * каждый пост — это запрос к модели, а бесплатная квота Gemini — десятки
     * запросов в сутки, и делить её приходится с обычным парсингом.
     */
    public function handle(TagDetectorService $tagDetector, LlmTaggerService $llmTagger): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $withLlm = (bool) $this->option('with-llm');

        $total = Post::query()->whereDoesntHave('tags')->count();

        if ($total === 0) {
            $this->info('Постов без тегов нет.');

            return self::SUCCESS;
        }

        $posts = Post::query()
            ->whereDoesntHave('tags')
            ->oldest('created_at')
            ->limit($limit)
            ->get();

        $this->info("Постов без тегов: {$total}. Партия: {$posts->count()} (лимит {$limit}). LLM: ".($withLlm ? 'да' : 'нет').'.');

        $tagged = 0;

        foreach ($posts as $post) {
            $tagIds = $tagDetector->detect($post->title, $post->url ?? '', $post->content ?? '');
            $source = 'словарь';

            if ($tagIds === [] && $withLlm) {
                $tagIds = $llmTagger->detect($post->title, $post->url ?? '', $post->content ?? '');
                $source = 'llm';
            }

            if ($tagIds === []) {
                $this->line("  #{$post->id} — не найдено: {$post->title}");

                continue;
            }

            $post->tags()->sync($tagIds);
            $tagged++;

            $titles = Tag::whereIn('id', $tagIds)->orderBy('title')->pluck('title')->implode(', ');
            $this->line("  #{$post->id} [{$source}] {$titles} — {$post->title}");
        }

        $left = Post::query()->whereDoesntHave('tags')->count();
        $this->info("Проставлено тегов постам: {$tagged}. Без тегов осталось: {$left}.");

        return self::SUCCESS;
    }
}

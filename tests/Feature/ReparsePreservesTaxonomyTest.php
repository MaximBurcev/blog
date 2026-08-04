<?php

namespace Tests\Feature;

use App\Filament\Resources\PostResource;
use App\Jobs\StorePostJob;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: действие «Перепарсить» отправляло в джобу
 * 'tag_ids' => [] и вовсе не передавало category_id. PostService запускает
 * автодетект только для пустых значений, поэтому расставленные вручную
 * категория и теги затирались результатом детектора — причём теги через
 * sync(), то есть начисто.
 */
class ReparsePreservesTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reparse_passes_current_category_and_tags(): void
    {
        Queue::fake();

        $category = Category::create(['title' => 'Базы данных', 'code' => 'databases']);
        $tags = collect(['MySQL', 'Индексы'])->map(
            fn (string $title, int $i) => Tag::create(['title' => $title, 'code' => 'tag-'.$i])
        );

        $post = Post::withoutSyncingToSearch(function () use ($category) {
            return Post::create([
                'title' => 'Статья про индексы',
                'code' => 'statya-pro-indeksy',
                'content' => 'текст',
                'url' => 'https://example.com/article',
                'category_id' => $category->id,
                'published' => true,
            ]);
        });

        $post->tags()->sync($tags->pluck('id')->all());

        PostResource::dispatchReparse($post->fresh());

        Queue::assertPushed(StorePostJob::class, function (StorePostJob $job) use ($category, $tags) {
            $payload = (fn () => $this->data)->call($job);

            return $payload['category_id'] === $category->id
                && $payload['tag_ids'] === $tags->pluck('id')->all();
        });
    }
}

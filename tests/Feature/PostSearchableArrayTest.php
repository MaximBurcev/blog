<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: без toSearchableArray() Scout отправлял в
 * Meilisearch модель целиком — вместе с content и content_orig, двумя LONGTEXT
 * на запись.
 *
 * Второй тест закрывает ошибку, допущенную при этой же правке: из документа
 * пропало поле published, а SearchController фильтрует по нему
 * (->where('published', true)) — выдача сайта молча стала пустой, хотя сам
 * индекс был полон.
 */
class PostSearchableArrayTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(): Post
    {
        return Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            return Post::create([
                'title' => 'Заголовок',
                'code' => 'zagolovok',
                'content' => '<p>Первый абзац.</p><p>Второй абзац.</p>',
                'content_orig' => '<p>Original English content</p>',
                'published' => true,
                'category_id' => $category->id,
            ]);
        });
    }

    public function test_original_content_is_not_sent_to_the_index(): void
    {
        $document = $this->makePost()->toSearchableArray();

        $this->assertArrayNotHasKey('content_orig', $document);
        $this->assertArrayNotHasKey('parse_error', $document);
        $this->assertArrayNotHasKey('url', $document);
    }

    public function test_published_flag_is_indexed_as_boolean(): void
    {
        $document = $this->makePost()->toSearchableArray();

        // Именно true, а не 1: фильтр Meilisearch сравнивает с булевым.
        $this->assertArrayHasKey('published', $document);
        $this->assertTrue($document['published']);
    }

    public function test_markup_is_stripped_without_gluing_words(): void
    {
        $document = $this->makePost()->toSearchableArray();

        $this->assertStringNotContainsString('<', $document['content']);
        // Теги заменяются пробелом: иначе «абзац.Второй» склеивалось бы в одно слово.
        $this->assertStringContainsString('Первый абзац. Второй абзац.', $document['content']);
    }

    public function test_category_title_is_indexed_for_search(): void
    {
        $this->assertSame('Laravel', $this->makePost()->toSearchableArray()['category']);
    }
}

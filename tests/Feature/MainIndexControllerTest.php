<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MainIndexControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_without_published_posts_is_not_shown(): void
    {
        Post::withoutSyncingToSearch(function () {
            $withPost = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $empty = Category::create(['title' => 'Symfony', 'code' => 'symfony']);

            Post::create([
                'title' => 'Test post',
                'code' => 'test-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $withPost->id,
            ]);
        });

        $response = $this->get(route('main.index'));

        $response->assertSee('Laravel');
        $response->assertDontSee('Symfony');
    }

    public function test_category_with_only_unpublished_posts_is_not_shown(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Docker', 'code' => 'docker']);

            Post::create([
                'title' => 'Draft post',
                'code' => 'draft-post',
                'content' => 'content',
                'published' => 0,
                'category_id' => $category->id,
            ]);
        });

        $response = $this->get(route('main.index'));

        $response->assertDontSee('Docker');
    }

    public function test_tag_without_published_posts_is_not_shown(): void
    {
        Post::withoutSyncingToSearch(function () {
            // Категория обязательна: главная страница выводит $post->category->title
            // без null-safe — пост без категории её просто уронит (отдельный,
            // не связанный с этой задачей риск, не трогаем здесь)
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $withPost = Tag::create(['title' => 'PHP', 'code' => 'php']);
            $empty = Tag::create(['title' => 'Unused', 'code' => 'unused']);

            $post = Post::create([
                'title' => 'Test post',
                'code' => 'test-post-2',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            $post->tags()->attach($withPost->id);
        });

        $response = $this->get(route('main.index'));

        $response->assertSee('PHP');
        $response->assertDontSee('Unused');
    }

    public function test_card_shows_excerpt_date_and_reading_time(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Test post',
                'code' => 'test-post-card',
                'content' => '<p>Анонсная фраза карточки и ещё немного текста.</p>',
                'published' => 1,
                'category_id' => $category->id,
            ]);
        });

        // Дата — published_at ?? created_at: при публикации published_at
        // ставит хук модели (Post::booted), поэтому это сегодняшний день.
        $this->get(route('main.index'))
            ->assertSee('Анонсная фраза карточки')
            ->assertSee('минута чтения')
            ->assertSee(now()->translatedFormat('j F Y'));
    }

    public function test_category_listing_card_shows_excerpt_and_reading_time(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Test post',
                'code' => 'test-post-category-card',
                'content' => '<p>Анонс категорийной карточки.</p>',
                'published' => 1,
                'category_id' => $category->id,
            ]);
        });

        $this->get(route('category.show', 'laravel'))
            ->assertSee('Анонс категорийной карточки')
            ->assertSee('минута чтения');
    }

    public function test_tag_listing_card_shows_excerpt_and_reading_time(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $tag = Tag::create(['title' => 'PHP', 'code' => 'php']);

            $post = Post::create([
                'title' => 'Test post',
                'code' => 'test-post-tag-card',
                'content' => '<p>Анонс карточки по тегу.</p>',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            $post->tags()->attach($tag->id);
        });

        $this->get(route('tag.show', 'php'))
            ->assertSee('Анонс карточки по тегу')
            ->assertSee('минута чтения');
    }

    public function test_header_has_search_form_and_section_links(): void
    {
        $this->get(route('main.index'))
            ->assertSee('action="'.route('main.search').'"', escape: false)
            ->assertSee('name="q"', escape: false)
            ->assertSee('href="'.route('category.index').'"', escape: false)
            ->assertSee('href="'.route('tag.index').'"', escape: false);
    }
}

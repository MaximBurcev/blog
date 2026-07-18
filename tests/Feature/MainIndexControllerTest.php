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
}

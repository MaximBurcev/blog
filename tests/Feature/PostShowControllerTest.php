<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostShowControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_code_returns_404(): void
    {
        $response = $this->get(route('post.show', 'does-not-exist'));

        $response->assertNotFound();
    }

    public function test_unpublished_post_returns_404(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            Post::create([
                'title' => 'Draft post',
                'code' => 'draft-post',
                'content' => 'content',
                'published' => 0,
                'category_id' => $category->id,
            ]);
        });

        $response = $this->get(route('post.show', 'draft-post'));

        $response->assertNotFound();
    }

    public function test_related_posts_prioritise_shared_tags_then_category(): void
    {
        Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
            $otherCategory = Category::create(['title' => 'Symfony', 'code' => 'symfony']);
            $sharedTag = Tag::create(['title' => 'PHP', 'code' => 'php']);

            $post = Post::create([
                'title' => 'Main post',
                'code' => 'main-post',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            $post->tags()->attach($sharedTag->id);

            // Общий тег + категория — должен идти первым
            $bestMatch = Post::create([
                'title' => 'Tag and category match',
                'code' => 'tag-and-category-match',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
            $bestMatch->tags()->attach($sharedTag->id);

            // Только категория, без общего тега
            Post::create([
                'title' => 'Category only match',
                'code' => 'category-only-match',
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);

            // Ни тега, ни категории — не должен попасть в топ
            Post::create([
                'title' => 'No match',
                'code' => 'no-match',
                'content' => 'content',
                'published' => 1,
                'category_id' => $otherCategory->id,
            ]);

            // Неопубликован — не должен попасть вообще, даже с общим тегом
            $unpublished = Post::create([
                'title' => 'Unpublished match',
                'code' => 'unpublished-match',
                'content' => 'content',
                'published' => 0,
                'category_id' => $category->id,
            ]);
            $unpublished->tags()->attach($sharedTag->id);
        });

        $post = Post::where('code', 'main-post')->firstOrFail();
        $related = Post::relatedTo($post)->get();

        $this->assertSame('tag-and-category-match', $related->first()->code);
        $this->assertTrue($related->pluck('code')->contains('category-only-match'));
        $this->assertFalse($related->pluck('code')->contains('unpublished-match'));
    }
}

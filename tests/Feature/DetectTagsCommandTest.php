<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DetectTagsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // LLM включён только в тесте про --with-llm (в phpunit.xml фича
        // выключена, см. комментарий там).
        config([
            'translation.gemini.key' => 'test-key',
            'translation.gemini.retry_delays_ms' => [0],
            'translation.circuit_breaker_seconds' => 0,
        ]);
    }

    public function test_dictionary_tags_untagged_posts_and_skips_tagged(): void
    {
        $laravel = Tag::create(['title' => 'Laravel', 'code' => 'laravel']);

        $untagged = $this->createPost('Laravel queues in practice');
        $tagged = $this->createPost('Another article');
        $tagged->tags()->sync([$laravel->id]);

        $this->artisan('posts:detect-tags')->assertSuccessful();

        $this->assertSame([$laravel->id], $untagged->refresh()->tags()->pluck('tags.id')->all());
        // Пост с тегами не трогаем: команда про архив без тегов, а не про
        // пересмотр чужой разметки.
        $this->assertSame([$laravel->id], $tagged->refresh()->tags()->pluck('tags.id')->all());
    }

    public function test_without_llm_flag_post_stays_untagged_when_dictionary_misses(): void
    {
        Http::fake();

        $post = $this->createPost('Integrating a US store with USPS labels');

        $this->artisan('posts:detect-tags')->assertSuccessful();

        $this->assertSame([], $post->refresh()->tags()->pluck('tags.id')->all());
        Http::assertNothingSent();
    }

    public function test_with_llm_flag_tags_post_via_llm_when_dictionary_misses(): void
    {
        config(['tagging.llm_enabled' => true]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '["USPS"]']]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $post = $this->createPost('Integrating a US store with USPS labels');

        $this->artisan('posts:detect-tags', ['--with-llm' => true])->assertSuccessful();

        $this->assertSame(['USPS'], $post->refresh()->tags()->pluck('title')->all());
    }

    public function test_nothing_to_do_when_every_post_is_tagged(): void
    {
        $this->artisan('posts:detect-tags')
            ->expectsOutputToContain('Постов без тегов нет')
            ->assertSuccessful();
    }

    private function createPost(string $title): Post
    {
        $category = Category::firstOrCreate(['code' => 'translations'], ['title' => 'Переводы']);

        return Post::create([
            'title' => $title,
            'code' => Str::slug($title),
            'content' => '<p>How to wire checkout to postage rates and print labels.</p>',
            'category_id' => $category->id,
            'published' => false,
        ]);
    }
}

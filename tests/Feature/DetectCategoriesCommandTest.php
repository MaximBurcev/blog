<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DetectCategoriesCommandTest extends TestCase
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

    public function test_dictionary_assigns_category_and_skips_categorized(): void
    {
        $laravel = Category::create(['title' => 'Laravel', 'code' => 'laravel']);
        $other = Category::create(['title' => 'Заметки', 'code' => 'notes']);

        $uncategorized = $this->createPost('Laravel queues in practice');
        $categorized = $this->createPost('Integrating a US store with USPS labels');
        $categorized->update(['category_id' => $other->id]);

        $this->artisan('posts:detect-categories')->assertSuccessful();

        $this->assertSame($laravel->id, $uncategorized->refresh()->category_id);
        // Пост с категорией не трогаем: команда про архив без раздела, а не
        // про пересмотр чужой разметки.
        $this->assertSame($other->id, $categorized->refresh()->category_id);
    }

    public function test_without_llm_flag_post_stays_uncategorized_when_dictionary_misses(): void
    {
        Http::fake();

        $post = $this->createPost('Integrating a US store with USPS labels');

        $this->artisan('posts:detect-categories')->assertSuccessful();

        $this->assertNull($post->refresh()->category_id);
        Http::assertNothingSent();
    }

    public function test_with_llm_flag_assigns_category_via_llm_when_dictionary_misses(): void
    {
        config(['tagging.llm_category_enabled' => true]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"category": "E-commerce"}']]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $post = $this->createPost('Integrating a US store with USPS labels');

        $this->artisan('posts:detect-categories', ['--with-llm' => true])->assertSuccessful();

        $this->assertSame('E-commerce', $post->refresh()->category?->title);
    }

    public function test_nothing_to_do_when_every_post_is_categorized(): void
    {
        $this->artisan('posts:detect-categories')
            ->expectsOutputToContain('Постов без категории нет')
            ->assertSuccessful();
    }

    private function createPost(string $title): Post
    {
        return Post::create([
            'title' => $title,
            'code' => Str::slug($title),
            'content' => '<p>How to wire checkout to postage rates and print labels.</p>',
            'published' => false,
        ]);
    }
}

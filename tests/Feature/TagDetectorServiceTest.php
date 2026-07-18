<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Service\TagDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagDetectorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_existing_tag_by_title_only(): void
    {
        $tag = Tag::create(['title' => 'Laravel', 'code' => 'laravel']);

        $result = (new TagDetectorService)->detect('New Laravel release notes', 'https://example.test/x');

        $this->assertSame([$tag->id], $result);
    }

    public function test_matches_existing_tag_found_only_in_content(): void
    {
        $tag = Tag::create(['title' => 'Laravel', 'code' => 'laravel']);

        $result = (new TagDetectorService)->detect(
            'The Blade Gotcha That 500s Your Page',
            'https://dev.to/nasrulhazim/the-blade-gotcha',
            '<p>This is a common mistake when working with Laravel Blade components.</p>'
        );

        $this->assertSame([$tag->id], $result);
    }

    public function test_auto_creates_tag_for_known_topic_not_yet_present(): void
    {
        $this->assertNull(Tag::where('title', 'Docker')->first());

        $result = (new TagDetectorService)->detect('Optimizing your Docker builds', 'https://example.test/docker-tips');

        $tag = Tag::find($result[0]);
        $this->assertNotNull($tag);
        $this->assertSame('Docker', $tag->title);
        $this->assertSame('docker', $tag->code);
    }

    public function test_returns_multiple_tags_for_multiple_topics(): void
    {
        $laravel = Tag::create(['title' => 'Laravel', 'code' => 'laravel']);

        $result = (new TagDetectorService)->detect(
            'Deploying a Laravel app with Docker and Redis',
            'https://example.test/x'
        );

        $this->assertContains($laravel->id, $result);
        $this->assertCount(3, $result);
        $titles = Tag::whereIn('id', $result)->pluck('title')->sort()->values()->all();
        $this->assertSame(['Docker', 'Laravel', 'Redis'], $titles);
    }

    public function test_auto_created_tag_is_reused_on_next_match(): void
    {
        $first = (new TagDetectorService)->detect('Docker guide', 'https://example.test/a');
        $second = (new TagDetectorService)->detect('Another Docker post', 'https://example.test/b');

        $this->assertSame($first, $second);
        $this->assertSame(1, Tag::where('title', 'Docker')->count());
    }

    public function test_case_insensitive_match_does_not_create_duplicate(): void
    {
        Tag::create(['title' => 'docker', 'code' => 'docker']);

        (new TagDetectorService)->detect('Docker guide', 'https://example.test/a');

        $this->assertSame(1, Tag::where('code', 'docker')->count());
    }

    public function test_short_keyword_does_not_match_inside_unrelated_word(): void
    {
        $result = (new TagDetectorService)->detect(
            'This article contains maintenance tips',
            'https://example.test/maintenance'
        );

        $this->assertSame([], $result);
        $this->assertNull(Tag::where('title', 'AI')->first());
    }

    public function test_existing_short_tag_does_not_match_inside_unrelated_word(): void
    {
        // Регрессия: как только "AI" реально СУЩЕСТВУЕТ как тег, прежняя
        // реализация матчила его через str_contains() без границ слова —
        // "ai" внутри "contains"/"domain"/"email" ложно попадал почти в
        // любую статью. Проверяем именно путь "уже существующий тег".
        Tag::create(['title' => 'AI', 'code' => 'ai']);

        $result = (new TagDetectorService)->detect(
            'This article contains information you can obtain by email',
            'https://example.test/domain-training'
        );

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_nothing_matches(): void
    {
        $result = (new TagDetectorService)->detect(
            'A completely unrelated topic about gardening',
            'https://example.test/gardening'
        );

        $this->assertSame([], $result);
    }
}

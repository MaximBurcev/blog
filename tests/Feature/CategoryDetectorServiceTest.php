<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Service\CategoryDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryDetectorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_existing_category_by_title_only(): void
    {
        $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

        $result = (new CategoryDetectorService)->detect('New Laravel release notes', 'https://example.test/x');

        $this->assertSame($category->id, $result);
    }

    public function test_matches_existing_category_found_only_in_content(): void
    {
        // Раньше это был баг: детектор не смотрел в content, только title+url
        $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

        $result = (new CategoryDetectorService)->detect(
            'The Blade Gotcha That 500s Your Page',
            'https://dev.to/nasrulhazim/the-blade-gotcha',
            '<p>This is a common mistake when working with Laravel Blade components.</p>'
        );

        $this->assertSame($category->id, $result);
    }

    public function test_auto_creates_category_for_known_topic_not_yet_present(): void
    {
        $this->assertNull(Category::where('title', 'Docker')->first());

        $result = (new CategoryDetectorService)->detect(
            'Optimizing your Docker builds',
            'https://example.test/docker-tips'
        );

        $category = Category::find($result);
        $this->assertNotNull($category);
        $this->assertSame('Docker', $category->title);
        $this->assertSame('docker', $category->code);
    }

    public function test_auto_created_category_is_reused_on_next_match(): void
    {
        $first = (new CategoryDetectorService)->detect('Docker guide', 'https://example.test/a');
        $second = (new CategoryDetectorService)->detect('Another Docker post', 'https://example.test/b');

        $this->assertSame($first, $second);
        $this->assertSame(1, Category::where('title', 'Docker')->count());
    }

    public function test_case_insensitive_match_does_not_create_duplicate(): void
    {
        // Категория уже существует в нижнем регистре — не должны завести
        // вторую "Docker" рядом с "docker"
        Category::create(['title' => 'docker', 'code' => 'docker']);

        (new CategoryDetectorService)->detect('Docker guide', 'https://example.test/a');

        $this->assertSame(1, Category::where('code', 'docker')->count());
    }

    public function test_short_keyword_does_not_match_inside_unrelated_word(): void
    {
        // "ai" не должен матчиться внутри "contains"/"maintain" и т.п.
        $result = (new CategoryDetectorService)->detect(
            'This article contains maintenance tips',
            'https://example.test/maintenance'
        );

        $this->assertNull($result);
        $this->assertNull(Category::where('title', 'AI')->first());
    }

    public function test_existing_short_category_does_not_match_inside_unrelated_word(): void
    {
        // Регрессия: как только "AI" реально СУЩЕСТВУЕТ как категория,
        // прежняя реализация матчила её через str_contains() без границ
        // слова — "ai" внутри "contains"/"domain"/"email" ложно попадал
        // почти в любую статью. Проверяем именно путь "уже существующая
        // категория", а не словарь тем.
        Category::create(['title' => 'AI', 'code' => 'ai']);

        $result = (new CategoryDetectorService)->detect(
            'This article contains information you can obtain by email',
            'https://example.test/domain-training'
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_nothing_matches(): void
    {
        $result = (new CategoryDetectorService)->detect(
            'A completely unrelated topic about gardening',
            'https://example.test/gardening'
        );

        $this->assertNull($result);
    }
}

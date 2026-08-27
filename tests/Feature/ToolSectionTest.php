<?php

namespace Tests\Feature;

use App\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolSectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTool(array $attributes = []): Tool
    {
        static $n = 0;
        $n++;

        return Tool::create(array_merge([
            'name' => 'vendor/package-'.$n,
            'url' => 'https://github.com/vendor/package-'.$n,
            'description' => 'Описание пакета '.$n.'.',
            'description_orig' => 'Tool '.$n.' description.',
            'is_published' => true,
        ], $attributes));
    }

    public function test_listing_shows_published_tools(): void
    {
        $this->makeTool(['name' => 'cerbero/json-parser']);

        $this->get(route('tools.index'))
            ->assertOk()
            ->assertSee('cerbero/json-parser')
            ->assertSee('https://github.com/vendor/package-1');
    }

    public function test_listing_hides_unpublished(): void
    {
        $this->makeTool(['name' => 'hidden/package', 'is_published' => false]);

        $this->get(route('tools.index'))->assertOk()->assertDontSee('hidden/package');
    }

    public function test_tool_without_translation_falls_back_to_the_original(): void
    {
        $this->makeTool([
            'description' => null,
            'description_orig' => 'Zero-dependencies pull parser for large JSON.',
        ]);

        $this->get(route('tools.index'))
            ->assertOk()
            ->assertSee('Zero-dependencies pull parser for large JSON.');
    }

    public function test_translation_wins_over_the_original(): void
    {
        $this->makeTool([
            'description' => 'Парсер JSON без зависимостей.',
            'description_orig' => 'Zero-dependencies pull parser.',
        ]);

        $this->get(route('tools.index'))
            ->assertOk()
            ->assertSee('Парсер JSON без зависимостей.')
            ->assertDontSee('Zero-dependencies pull parser.');
    }

    public function test_empty_listing_does_not_break(): void
    {
        $this->get(route('tools.index'))->assertOk()->assertSee('Инструментов пока нет');
    }

    public function test_empty_listing_is_noindex(): void
    {
        $this->get(route('tools.index'))
            ->assertOk()
            ->assertSee('noindex, follow');

        $this->makeTool();

        $this->get(route('tools.index'))
            ->assertOk()
            ->assertDontSee('noindex, follow');
    }

    public function test_listing_is_reachable_from_the_menu(): void
    {
        $this->get('/')->assertOk()->assertSee(route('tools.index'), escape: false);
    }

    public function test_sitemap_includes_the_section_only_when_there_are_tools(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertDontSee(route('tools.index'), escape: false);
        $this->get(route('sitemap.index'))->assertOk()->assertDontSee('Утилиты и библиотеки');

        $this->makeTool();

        $this->get('/sitemap.xml')->assertOk()->assertSee(route('tools.index'), escape: false);
        $this->get(route('sitemap.index'))->assertOk()->assertSee('Утилиты и библиотеки');
    }

    public function test_unpublished_tools_do_not_open_the_section(): void
    {
        $this->makeTool(['is_published' => false]);

        $this->get('/sitemap.xml')->assertOk()->assertDontSee(route('tools.index'), escape: false);
    }
}

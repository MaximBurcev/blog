<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\ReleaseResource;
use App\Filament\Resources\ToolResource;
use App\Jobs\ImportToolsJob;
use App\Models\Release;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ToolAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_tool_list_renders(): void
    {
        Tool::create([
            'name' => 'cerbero/json-parser',
            'url' => 'https://github.com/cerbero90/json-parser',
            'description' => 'Парсер JSON без зависимостей.',
            'description_orig' => 'Zero-dependencies pull parser.',
        ]);

        Tool::create([
            'name' => 'mnapoli/simple-s3',
            'url' => 'https://github.com/mnapoli/simple-s3',
            'description' => null,
            'description_orig' => 'Simple, single-file AWS S3 client.',
        ]);

        $this->actingAs($this->admin())
            ->get(ToolResource::getUrl('index'))
            ->assertOk()
            ->assertSee('cerbero/json-parser')
            ->assertSee('Simple, single-file AWS S3 client.');
    }

    public function test_tool_form_pages_render(): void
    {
        $tool = Tool::create([
            'name' => 'vendor/package',
            'url' => 'https://github.com/vendor/package',
            'description_orig' => 'Description.',
        ]);

        $this->actingAs($this->admin());

        $this->get(ToolResource::getUrl('create'))->assertOk();
        $this->get(ToolResource::getUrl('edit', ['record' => $tool]))->assertOk();
    }

    public function test_import_action_queues_the_job(): void
    {
        Queue::fake();

        $release = Release::create(['url' => 'https://example.test/digest']);

        ReleaseResource::dispatchToolsImport($release);

        Queue::assertPushed(ImportToolsJob::class);
    }
}

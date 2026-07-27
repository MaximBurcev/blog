<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\PostCreated;
use App\Filament\Resources\PostResource\Pages\CreatePost;
use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Регрессия на architecture-audit-2026-07-24 H3: дефолтный
 * CreateRecord::handleRecordCreation() делает голый Post::create($data),
 * минуя PostService — посты, созданные через Filament (/filament — живая
 * админка, в отличие от отключённого /admin), не получали ни авто-детект
 * тегов, ни событие PostCreated. CreatePost теперь явно зовёт PostService.
 */
class FilamentCreatePostTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_post_via_filament_goes_through_post_service(): void
    {
        Event::fake([PostCreated::class]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

        Post::withoutSyncingToSearch(function () use ($admin, $category) {
            Livewire::actingAs($admin)
                ->test(CreatePost::class)
                ->fillForm([
                    'title' => 'Admin Created Post',
                    'code' => 'admin-created-post',
                    'content' => '<p>Laravel Livewire Filament content</p>',
                    'category_id' => $category->id,
                    'published' => true,
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        });

        $post = Post::where('code', 'admin-created-post')->first();

        $this->assertNotNull($post);
        Event::assertDispatched(PostCreated::class, fn ($event) => $event->post->is($post));
    }
}

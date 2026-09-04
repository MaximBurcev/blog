<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Analytics;
use App\Filament\Resources\PostResource\Pages\ListPosts;
use App\Filament\Resources\UserResource;
use App\Models\Post;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Роль Editor (волна E, «Роли: Editor/Author»): редактор работает с контентом
 * панели наравне с админом, но до пользователей его не допускает UserPolicy.
 * Доступ в саму панель — User::canAccessPanel, остальное — политики моделей,
 * которые Filament 3 подхватывает автоматически (явных canViewAny в ресурсах
 * нет и не нужно).
 */
class FilamentEditorRoleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Editor]);
    }

    private function reader(): User
    {
        return User::factory()->create(['role' => UserRole::Reader]);
    }

    /**
     * Не post(): в Laravel 13 у Illuminate\Foundation\Testing\TestCase есть
     * публичный post() для HTTP-запросов, и одноимённый приватный метод
     * роняет класс на fatal.
     */
    private function makePost(array $attributes = []): Post
    {
        static $n = 0;
        $n++;

        return Post::create(array_merge([
            'title' => 'Заголовок '.$n,
            'content' => '<p>Текст записи '.$n.'.</p>',
            'code' => 'zapis-'.$n,
            'published' => false,
            'is_news' => false,
        ], $attributes));
    }

    public function test_editor_can_access_panel(): void
    {
        $editor = $this->editor();

        $this->assertTrue($editor->canAccessPanel(Filament::getCurrentPanel() ?? Filament::getDefaultPanel()));

        $this->actingAs($editor)->get('/'.config('admin.panel_path', 'filament'))->assertOk();
    }

    public function test_reader_cannot_access_panel(): void
    {
        $reader = $this->reader();

        $this->assertFalse($reader->canAccessPanel(Filament::getCurrentPanel() ?? Filament::getDefaultPanel()));

        $this->actingAs($reader)->get('/'.config('admin.panel_path', 'filament'))->assertForbidden();
    }

    public function test_editor_sees_posts_and_can_bulk_publish(): void
    {
        Post::withoutSyncingToSearch(function () {
            $drafts = collect([$this->makePost(), $this->makePost()]);

            Livewire::actingAs($this->editor())
                ->test(ListPosts::class)
                ->assertCanSeeTableRecords($drafts->all())
                ->callTableBulkAction('publish', $drafts);

            foreach ($drafts as $draft) {
                $this->assertTrue($draft->fresh()->published);
            }
        });
    }

    public function test_editor_cannot_open_user_resource(): void
    {
        $editor = $this->editor();

        // Политика прячет ресурс из навигации, а прямой URL режется 403.
        $this->actingAs($editor);

        $this->assertFalse(UserResource::canViewAny());

        $this->get(UserResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_editor_cannot_touch_users_via_policy(): void
    {
        $editor = $this->editor();
        $target = User::factory()->create(['role' => UserRole::Reader]);

        foreach (['viewAny', 'create', 'deleteAny'] as $ability) {
            $this->assertFalse(Gate::forUser($editor)->allows($ability, User::class), $ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse(Gate::forUser($editor)->allows($ability, $target), $ability);
        }
    }

    public function test_editor_opens_analytics_page(): void
    {
        $this->actingAs($this->editor())
            ->get(Analytics::getUrl())
            ->assertOk();
    }

    public function test_admin_keeps_full_access(): void
    {
        $admin = $this->admin();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', User::class));

        $this->actingAs($admin)
            ->get(UserResource::getUrl('index'))
            ->assertOk();

        Post::withoutSyncingToSearch(function () use ($admin) {
            $post = $this->makePost();

            Livewire::actingAs($admin)
                ->test(ListPosts::class)
                ->assertCanSeeTableRecords([$post]);
        });
    }

    /**
     * Политики режут по роли, а инвариант «последний админ неудалим»
     * остаётся на месте: UserResource::canDelete его не делегировал
     * политике и раньше, теперь обе проверки работают вместе.
     */
    public function test_last_admin_invariant_still_holds(): void
    {
        User::query()->delete();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin);

        $this->assertFalse(UserResource::canDelete($admin));

        $editor = User::factory()->create(['role' => UserRole::Editor]);

        $this->assertTrue(UserResource::canDelete($editor));
    }

    public function test_reader_denied_by_content_policies(): void
    {
        $reader = $this->reader();

        $this->assertFalse(Gate::forUser($reader)->allows('viewAny', Post::class));

        $post = $this->makePost(['published' => true]);

        $this->assertFalse(Gate::forUser($reader)->allows('update', $post));
    }
}

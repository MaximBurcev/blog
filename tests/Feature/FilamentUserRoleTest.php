<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\Category;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: роли не было ни в одной форме приложения —
 * созданный из панели пользователь получал role = NULL (колонка nullable без
 * default) и сам в панель попасть уже не мог (User::canAccessPanel() требует
 * Admin). Нового администратора заводили руками в БД.
 *
 * Плюс unique() без ignoreRecord: true — существующую запись невозможно было
 * сохранить из админки, валидатор ругался на её же собственное значение.
 */
class FilamentUserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user_with_explicit_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Новый админ',
                'email' => 'new-admin@example.com',
                'password' => 'Str0ng-Passw0rd!',
                'role' => UserRole::Admin->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'new-admin@example.com')->firstOrFail();

        $this->assertSame(UserRole::Admin, $created->role);
        // Смысл роли именно в этом: без неё созданный пользователь не попадал
        // в ту самую панель, из которой его завели.
        $this->assertTrue($created->canAccessPanel(Filament::getCurrentPanel() ?? Filament::getDefaultPanel()));
    }

    public function test_role_is_required(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Без роли',
                'email' => 'no-role@example.com',
                'password' => 'Str0ng-Passw0rd!',
                'role' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'no-role@example.com']);
    }

    public function test_existing_category_can_be_saved_without_touching_its_code(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

        Livewire::actingAs($admin)
            ->test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm(['title' => 'Laravel и экосистема'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Laravel и экосистема', $category->refresh()->title);
    }
}

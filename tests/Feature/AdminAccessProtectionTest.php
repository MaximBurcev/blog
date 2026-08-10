<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\LastAdminException;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Регрессии на MIN-8 и INF-4 security-аудита от 2026-07-24:
 * логи не должны быть доступны без роли Admin, а последний админ —
 * удалим из панели.
 */
class AdminAccessProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_viewer_gate_denies_guests_and_readers(): void
    {
        $reader = User::factory()->create(['role' => UserRole::Reader]);

        $this->assertFalse(Gate::forUser(null)->allows('viewLogViewer'));
        $this->assertFalse(Gate::forUser($reader)->allows('viewLogViewer'));
    }

    public function test_log_viewer_gate_allows_admins(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->assertTrue(Gate::forUser($admin)->allows('viewLogViewer'));
    }

    public function test_last_admin_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->assertFalse(UserResource::canDelete($admin));
    }

    public function test_admin_cannot_delete_himself(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin);

        $this->assertFalse(UserResource::canDelete($admin));
    }

    public function test_admin_can_be_deleted_when_another_admin_remains(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin);

        $this->assertTrue(UserResource::canDelete($other));
    }

    public function test_reader_can_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $reader = User::factory()->create(['role' => UserRole::Reader]);

        $this->actingAs($admin);

        $this->assertTrue(UserResource::canDelete($reader));
    }

    /**
     * Регрессия на аудит 2026-08-10: disableOptionWhen в Filament — чисто
     * визуальный контроль (getEnabledOptions() фреймворком нигде не
     * вызывается, Rule::in из него не выводится), поэтому Livewire-запрос
     * с role=Reader проходил мимо и отбирал доступ к панели безвозвратно.
     * Инвариант обязан жить на модели.
     */
    public function test_last_admin_cannot_be_demoted(): void
    {
        User::query()->delete();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->expectException(LastAdminException::class);

        $admin->update(['role' => UserRole::Reader]);
    }

    public function test_last_admin_role_survives_the_attempt(): void
    {
        User::query()->delete();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        try {
            $admin->update(['role' => UserRole::Reader]);
        } catch (LastAdminException) {
            // ожидаемо
        }

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_admin_can_be_demoted_when_another_admin_remains(): void
    {
        User::query()->delete();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['role' => UserRole::Admin]);

        $admin->update(['role' => UserRole::Reader]);

        $this->assertSame(UserRole::Reader, $admin->fresh()->role);
    }

    /**
     * Форма редактирования должна открываться: в Select роли появился
     * disableOptionWhen, закрывающий понижение себя/последнего админа
     * (аудит 2026-08-09) — ошибка в его сигнатуре ломала бы страницу целиком,
     * а проверки canDelete выше рендер формы не затрагивают.
     */
    public function test_user_edit_form_renders_for_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin);

        $this->get(UserResource::getUrl('edit', ['record' => $admin]))->assertOk();
        $this->get(UserResource::getUrl('index'))->assertOk();
    }
}

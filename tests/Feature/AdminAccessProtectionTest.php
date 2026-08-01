<?php

namespace Tests\Feature;

use App\Enums\UserRole;
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
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на security-audit-2026-07-24 MIN-8: opcodesio/log-viewer не
 * проверяет доступ вообще, если gate 'viewLogViewer' не определён
 * (LogViewerService::auth() — no-op). Логи содержат стектрейсы/SQL/payload'ы,
 * поэтому /log-viewer должен требовать авторизации админа.
 */
class LogViewerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_log_viewer(): void
    {
        $response = $this->get('/log-viewer');

        $response->assertForbidden();
    }

    public function test_reader_cannot_access_log_viewer(): void
    {
        $reader = User::factory()->create(['role' => UserRole::Reader]);

        $response = $this->actingAs($reader)->get('/log-viewer');

        $response->assertForbidden();
    }

    public function test_admin_can_access_log_viewer(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->get('/log-viewer');

        $response->assertOk();
    }
}

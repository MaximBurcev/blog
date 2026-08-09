<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Audit-trail событий безопасности (security-audit-2026-08-01, INF-7):
 * до этого ни входы, ни неудачные попытки подбора, ни смена роли и пароля
 * не фиксировались нигде, и разбирать инцидент было не по чему.
 *
 * Канал security подменяется на одиночный файл во временном каталоге, чтобы
 * тест проверял весь путь целиком — включая то, что запись действительно
 * уходит в отдельный канал, а не в общий laravel.log.
 */
class SecurityAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir().'/security_audit_'.uniqid().'.log';

        config(['logging.channels.security' => [
            'driver' => 'single',
            'path' => $this->logFile,
            'level' => 'info',
        ]]);

        // Канал мог быть разрешён и закэширован предыдущим тестом — иначе
        // писать продолжит старый handler на боевой путь.
        Log::forgetChannel('security');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    private function auditLog(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    public function test_successful_login_is_recorded(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.com']);

        $this->post('/login', [
            'email' => 'reader@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);

        $log = $this->auditLog();
        $this->assertStringContainsString('auth.login', $log);
        $this->assertStringContainsString('reader@example.com', $log);
    }

    public function test_failed_login_is_recorded_without_the_password(): void
    {
        User::factory()->create(['email' => 'reader@example.com']);

        $this->post('/login', [
            'email' => 'reader@example.com',
            'password' => 'wrong-password-hunter2',
        ]);

        $this->assertGuest();

        $log = $this->auditLog();
        $this->assertStringContainsString('auth.failed', $log);
        $this->assertStringContainsString('reader@example.com', $log);
        // Событие Failed несёт credentials целиком — пароль в лог попасть
        // не должен ни при каких обстоятельствах.
        $this->assertStringNotContainsString('hunter2', $log);
    }

    public function test_failed_login_for_unknown_email_marks_user_as_absent(): void
    {
        $this->post('/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $log = $this->auditLog();
        $this->assertStringContainsString('auth.failed', $log);
        $this->assertStringContainsString('"user_exists":false', $log);
    }

    public function test_role_change_is_recorded_with_both_values(): void
    {
        $user = User::factory()->create(['role' => UserRole::Reader]);

        $user->update(['role' => UserRole::Admin]);

        $log = $this->auditLog();
        $this->assertStringContainsString('user.role_changed', $log);
        $this->assertStringContainsString('"from":'.UserRole::Reader->value, $log);
        $this->assertStringContainsString('"to":'.UserRole::Admin->value, $log);
    }

    public function test_password_change_is_recorded_without_the_hash(): void
    {
        $user = User::factory()->create();

        $user->update(['password' => Hash::make('brand-new-password')]);

        $log = $this->auditLog();
        $this->assertStringContainsString('user.password_changed', $log);
        $this->assertStringNotContainsString('brand-new-password', $log);
        $this->assertStringNotContainsString($user->fresh()->password, $log);
    }

    public function test_creating_a_user_does_not_produce_a_password_change_entry(): void
    {
        User::factory()->create();

        $this->assertStringNotContainsString('user.password_changed', $this->auditLog());
    }

    public function test_user_deletion_is_recorded(): void
    {
        $user = User::factory()->create(['email' => 'gone@example.com']);

        $user->delete();

        $log = $this->auditLog();
        $this->assertStringContainsString('user.deleted', $log);
        $this->assertStringContainsString('gone@example.com', $log);
    }
}

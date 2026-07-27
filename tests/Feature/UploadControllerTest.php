<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Регрессия на RCE (test-audit-2026-07-24 C1): раньше /upload принимал любой
 * файл без проверки типа/расширения — можно было залить .php под видом
 * картинки и получить его прямую ссылку в storage/public (веб-доступный RCE).
 * UploadRequest теперь требует image|mimes:jpg,jpeg,png,gif,webp|max:5120.
 */
class UploadControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_guest_cannot_upload(): void
    {
        Storage::fake('public');

        $response = $this->post(route('upload'), [
            'file' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_reader_cannot_upload(): void
    {
        Storage::fake('public');
        $reader = User::factory()->create(['role' => UserRole::Reader]);

        $response = $this->actingAs($reader)->post(route('upload'), [
            'file' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        // AdminMiddleware делает abort(404), а не 403 — не раскрывает
        // не-админам сам факт существования маршрута.
        $response->assertNotFound();
    }

    public function test_php_file_disguised_as_image_is_rejected(): void
    {
        Storage::fake('public');

        $phpPayload = UploadedFile::fake()->createWithContent(
            'shell.php',
            '<?php echo "pwned"; ?>'
        );

        $response = $this->actingAs($this->admin())->post(route('upload'), [
            'file' => $phpPayload,
        ]);

        $response->assertSessionHasErrors('file');
        Storage::disk('public')->assertMissing('images/shell.php');
    }

    public function test_php_file_renamed_with_jpg_extension_is_rejected(): void
    {
        Storage::fake('public');

        // UploadedFile::fake() (Illuminate\Http\Testing\File) определяет MIME
        // только по расширению имени файла, не по содержимому — не годится
        // для этого сценария. Строим настоящий UploadedFile поверх реального
        // временного файла, чтобы 'image' в валидации действительно сработала
        // через content-sniffing (как и в проде), а не просто поверила .jpg.
        $path = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($path, '<?php echo "pwned"; ?>');
        $malicious = new UploadedFile($path, 'shell.jpg', null, null, true);

        $response = $this->actingAs($this->admin())->post(route('upload'), [
            'file' => $malicious,
        ]);

        $response->assertSessionHasErrors('file');
        unlink($path);
    }

    public function test_admin_can_upload_valid_image(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin())->post(route('upload'), [
            'file' => UploadedFile::fake()->image('avatar.jpg', 100, 100),
        ]);

        $response->assertSuccessful();
        $this->assertNotEmpty($response->getContent());
    }

    public function test_oversized_image_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin())->post(route('upload'), [
            // max:5120 KB — берём заведомо больше
            'file' => UploadedFile::fake()->image('big.jpg')->size(6000),
        ]);

        $response->assertSessionHasErrors('file');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на mass-assignment (test-audit-2026-07-24 C4): 'role' входит в
 * $fillable у User (нужно для админки), поэтому регистрация обязана сама
 * собирать массив полей, а не полагаться на $request->all()/validated() —
 * иначе обычный посетитель мог бы прописать role=0 (админ) прямо в форме
 * регистрации.
 */
class RegisterMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_ignores_role_field_from_request(): void
    {
        $response = $this->post('/register', [
            'name' => 'Attacker',
            'email' => 'attacker@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => UserRole::Admin->value,
        ]);

        $response->assertRedirect();

        $user = User::where('email', 'attacker@example.test')->firstOrFail();

        $this->assertNotSame(UserRole::Admin, $user->role);
    }
}

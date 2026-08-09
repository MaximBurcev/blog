<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\SecurityAudit;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * MustVerifyEmail: регистрация открыта всем, и без подтверждения адреса
 * аккаунт можно было завести на несуществующий ящик. Вместе с honeypot и
 * лимитом на IP (RouteServiceProvider) это третий рубеж против массового
 * создания фейковых читателей.
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;
    use SoftDeletes;

    /**
     * @return array<int, string>
     */
    public static function getRoles(): array
    {
        return UserRole::options();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Audit-trail изменений учётных записей (security-audit 2026-08-01, INF-7).
     *
     * Хуки модели, а не вызовы из UserResource: сменить роль или пароль можно
     * из формы Filament, из отдельного действия «Сменить пароль», через сброс
     * пароля и из tinker — на модели все эти пути сходятся в одну точку, и
     * добавление нового не потребует не забыть про логирование.
     *
     * Событие created не логируем: регистрацию уже пишет
     * SecurityEventSubscriber по Registered.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('role')) {
                $original = $user->getOriginal('role');

                SecurityAudit::log('user.role_changed', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'from' => $original instanceof UserRole ? $original->value : $original,
                    'to' => $user->role?->value,
                ]);
            }

            // Значение пароля (даже хэш) в лог не идёт — только факт смены.
            if ($user->wasChanged('password')) {
                SecurityAudit::log('user.password_changed', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }
        });

        static::deleted(function (User $user): void {
            SecurityAudit::log('user.deleted', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->role?->value,
                'soft' => ! $user->isForceDeleting(),
            ]);
        });
    }
}

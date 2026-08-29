<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Exceptions\LastAdminException;
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
        // Editor пущен в панель, но его урезает политика UserPolicy:
        // управление пользователями остаётся только за Admin.
        return $this->role === UserRole::Admin || $this->role === UserRole::Editor;
    }

    /**
     * Остался ли этот администратор единственным. Считаем по не удалённым
     * записям — soft-deleted в панель всё равно не попадёт.
     */
    private static function isLastAdmin(self $user): bool
    {
        return self::query()
            ->where('role', UserRole::Admin)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
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
        // Серверный инвариант, а не только UI. disableOptionWhen в Filament —
        // чисто визуальный контроль: getEnabledOptions() фреймворком нигде не
        // вызывается, и Rule::in из него не выводится. То есть Livewire-запрос
        // с role=Reader для последнего админа проходил и отбирал доступ к
        // панели безвозвратно (роль правится только руками в БД).
        static::updating(function (User $user): void {
            if (! $user->isDirty('role')) {
                return;
            }

            $wasAdmin = $user->getOriginal('role') === UserRole::Admin;

            if ($wasAdmin && $user->role !== UserRole::Admin && self::isLastAdmin($user)) {
                throw new LastAdminException(
                    'Нельзя снять роль администратора с последнего администратора.'
                );
            }
        });

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

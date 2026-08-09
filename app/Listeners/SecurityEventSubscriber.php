<?php

namespace App\Listeners;

use App\Support\SecurityAudit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;

/**
 * Audit-trail аутентификации (security-audit 2026-08-01, INF-7).
 *
 * Подписчик, а не набор отдельных листенеров: событий семь, обработка у всех
 * одинаковая (собрать актора и записать в канал security), и раскладывать их
 * по семи классам — только размазывать одну мысль по файлам.
 *
 * Синхронный: очередь тут вредна. Событие безопасности должно попасть в лог
 * в момент, когда оно произошло, а не когда до него дойдёт воркер — тем более
 * что упавшая очередь как раз и может быть частью инцидента.
 */
class SecurityEventSubscriber
{
    public function handleLogin(Login $event): void
    {
        SecurityAudit::log('auth.login', [
            'user_id' => $event->user->getAuthIdentifier(),
            'email' => $event->user->email ?? null,
            'guard' => $event->guard,
            'remember' => $event->remember,
        ]);
    }

    /**
     * $event->credentials содержит и пароль в открытом виде — в лог уходит
     * только идентификатор, иначе подбор пароля к чужому аккаунту сохранял бы
     * в laravel-логи рабочие пароли пользователей, перепутавших поле.
     */
    public function handleFailed(Failed $event): void
    {
        SecurityAudit::log('auth.failed', [
            'user_id' => $event->user?->getAuthIdentifier(),
            'email' => $event->credentials['email'] ?? null,
            'guard' => $event->guard,
            'user_exists' => $event->user !== null,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        SecurityAudit::log('auth.logout', [
            'user_id' => $event->user?->getAuthIdentifier(),
            'email' => $event->user->email ?? null,
            'guard' => $event->guard,
        ]);
    }

    /**
     * Сработавший throttle: либо подбор пароля, либо перебор адресов через
     * форму сброса. Самое ценное событие в этом списке.
     */
    public function handleLockout(Lockout $event): void
    {
        SecurityAudit::log('auth.lockout', [
            'email' => $event->request->input('email'),
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        SecurityAudit::log('auth.password_reset', [
            'user_id' => $event->user->getAuthIdentifier(),
            'email' => $event->user->email ?? null,
        ]);
    }

    public function handleRegistered(Registered $event): void
    {
        SecurityAudit::log('auth.registered', [
            'user_id' => $event->user->getAuthIdentifier(),
            'email' => $event->user->email ?? null,
        ]);
    }

    public function handleVerified(Verified $event): void
    {
        SecurityAudit::log('auth.email_verified', [
            'user_id' => $event->user->getAuthIdentifier(),
            'email' => $event->user->email ?? null,
        ]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Logout::class => 'handleLogout',
            Lockout::class => 'handleLockout',
            PasswordReset::class => 'handlePasswordReset',
            Registered::class => 'handleRegistered',
            Verified::class => 'handleVerified',
        ];
    }
}

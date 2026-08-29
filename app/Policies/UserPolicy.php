<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Управление пользователями — только Admin. Editor в панель пущен
 * (User::canAccessPanel), но до UserResource его не допускает viewAny:
 * Filament прячет ресурс из навигации и отвечает 403 на прямой URL.
 *
 * Инвариант «нельзя удалить себя/последнего админа» здесь не дублируется:
 * он живёт в UserResource::canDelete() (UI) и в updating-хуке модели
 * (серверный рубеж), эта политика отвечает только за «кто вообще трогает
 * пользователей».
 */
class UserPolicy
{
    protected function allows(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user);
    }

    public function view(User $user, User $record): bool
    {
        return $this->allows($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user);
    }

    public function update(User $user, User $record): bool
    {
        return $this->allows($user);
    }

    public function delete(User $user, User $record): bool
    {
        return $this->allows($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user);
    }
}

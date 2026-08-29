<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Базовая политика контентных ресурсов панели: всё, чем работает
 * редакторская команда (посты, таксономия, комментарии, селекторы парсинга).
 *
 * Admin и Editor равноправны; Reader получает false — защита вглубь на
 * случай, если Reader когда-нибудь пустят в панель (сейчас его режет
 * User::canAccessPanel). Гостя сюда не позовут: публичная часть сайта
 * политиками не пользуется.
 *
 * Filament 3 подхватывает политику модели автоматически: canViewAny/canCreate/
 * canEdit/canDelete ресурса сводятся к методам viewAny/create/update/delete
 * ниже, bulk-удаление — к deleteAny, Restore/ForceDelete-экшены (ToolResource)
 * — к restore/forceDelete. Методы объявлены явно: при shouldCheckPolicyExistence
 * отсутствующий метод политики трактовался бы как «разрешено».
 */
abstract class ContentPolicy
{
    protected function allows(User $user): bool
    {
        return $user->role === UserRole::Admin || $user->role === UserRole::Editor;
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->allows($user);
    }

    public function create(User $user): bool
    {
        return $this->allows($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user);
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->allows($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->allows($user);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return $this->allows($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->allows($user);
    }
}

<?php

namespace App\Enums;

enum UserRole: int
{
    case Admin = 0;
    case Reader = 1;
    // Значение 2, а не между 0 и 1: колонка хранит число, перестановка
    // существующих значений переломала бы роли всем уже заведённым
    // пользователям.
    case Editor = 2;

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Админ',
            self::Reader => 'Читатель',
            self::Editor => 'Редактор',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $role) => $role->value, self::cases()),
            array_map(fn (self $role) => $role->label(), self::cases()),
        );
    }
}

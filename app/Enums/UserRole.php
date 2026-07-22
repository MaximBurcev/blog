<?php

namespace App\Enums;

enum UserRole: int
{
    case Admin = 0;
    case Reader = 1;

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Админ',
            self::Reader => 'Читатель',
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

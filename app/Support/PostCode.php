<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Единственное место, где рождается code поста — часть его публичного адреса
 * (/posts/{code}).
 *
 * Правило было размазано по коду и расходилось: PostService::store() звал
 * Str::slug($title, '-', 'ru'), форма Filament — Str::slug($state) без локали,
 * а TranslatePostCommand — третий вариант. Кириллица при этом
 * транслитерируется РАЗНЫМИ таблицами («Цикл жизни» → cikl-zizni против
 * tsikl-zhizni), поэтому показанный в форме адрес не совпадал с сохранённым.
 */
class PostCode
{
    /**
     * Локаль 'ru' обязательна: без неё Str::slug использует общую таблицу
     * транслитерации и кириллические заголовки превращаются в неузнаваемое.
     */
    public static function fromTitle(?string $title): string
    {
        // Пост от неудавшегося парсинга может остаться без осмысленного
        // заголовка, а пустой code сломал бы маршрут.
        return Str::slug((string) $title, '-', 'ru') ?: 'post-'.Str::random(8);
    }

    /**
     * Приводит введённое руками значение к тому же виду, что и генерация из
     * заголовка: администратор может напечатать пробелы, заглавные буквы или
     * кириллицу, а в адресе это недопустимо.
     */
    public static function normalize(?string $code): string
    {
        return self::fromTitle($code);
    }
}

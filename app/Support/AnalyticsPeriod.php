<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Период выборки для страницы аналитики: один источник правды для всех её
 * виджетов, чтобы плитки, график и топ постов считали одно и то же окно.
 *
 * Значение приезжает из фильтра страницы, а он объявлен через #[Url]
 * (Filament\Pages\Dashboard\Concerns\HasFilters), то есть приходит из
 * query-string и пользователем управляем. Отсюда белый список: без него
 * ?filters[period]=100000 означал бы полный скан post_views, а нецелое
 * значение — падение при вычитании дней.
 */
final class AnalyticsPeriod
{
    public const DEFAULT_DAYS = 30;

    /**
     * Варианты фильтра и их подписи в трёх нужных формах.
     *
     * Формы записаны, а не собраны из числа склонением: «За 7 дней» рядом с
     * выбранным в селекте вариантом «Неделя» — это два названия одного периода
     * на одном экране. Заодно снимается вопрос согласования рода в «предыдущую
     * неделю» / «предыдущий месяц».
     *
     * @var array<int, array{option: string, accusative: string, previous: string}>
     */
    private const FORMS = [
        7 => ['option' => 'Неделя', 'accusative' => 'неделю', 'previous' => 'предыдущую неделю'],
        30 => ['option' => 'Месяц', 'accusative' => 'месяц', 'previous' => 'предыдущий месяц'],
        90 => ['option' => 'Квартал', 'accusative' => 'квартал', 'previous' => 'предыдущий квартал'],
    ];

    /**
     * Варианты для Select фильтра.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $forms): string => $forms['option'], self::FORMS);
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public static function days(?array $filters): int
    {
        $days = (int) ($filters['period'] ?? self::DEFAULT_DAYS);

        return isset(self::FORMS[$days]) ? $days : self::DEFAULT_DAYS;
    }

    /**
     * Начало периода. Сегодняшний день входит в него целиком: «неделя» — это
     * сегодня и шесть предыдущих суток, иначе на графике оказывалось бы восемь
     * точек вместо семи.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function startsAt(?array $filters): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays(self::days($filters) - 1)->startOfDay();
    }

    /**
     * Начало предыдущего такого же периода — база для расчёта динамики.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function previousStartsAt(?array $filters): CarbonImmutable
    {
        return self::startsAt($filters)->subDays(self::days($filters));
    }

    /**
     * Конец предыдущего периода — ровно на N суток раньше «сейчас», а не
     * начало текущего окна.
     *
     * Иначе окна получаются разной длины: текущее — это N-1 полных суток плюс
     * прошедшая часть сегодняшнего дня, а предыдущее было бы N полных суток.
     * Утром при ровном трафике такая пара давала бы «−9%» и красную стрелку на
     * пустом месте, и чем раньше открыть страницу, тем сильнее мнимое падение.
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function previousEndsAt(?array $filters): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays(self::days($filters));
    }

    /**
     * «За неделю», «Читателей за месяц».
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function label(?array $filters): string
    {
        return self::FORMS[self::days($filters)]['accusative'];
    }

    /**
     * «…к тому, что было в предыдущую неделю».
     *
     * @param  array<string, mixed>|null  $filters
     */
    public static function previousLabel(?array $filters): string
    {
        return self::FORMS[self::days($filters)]['previous'];
    }
}

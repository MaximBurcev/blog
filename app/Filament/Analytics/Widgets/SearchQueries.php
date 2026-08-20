<?php

namespace App\Filament\Analytics\Widgets;

use App\Models\SearchQuery;
use App\Support\AnalyticsPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Что читатели ищут на сайте — и чего не находят.
 *
 * Единственный отчёт блога, где тему подсказывает сам читатель: остальная
 * аналитика говорит, что из написанного читают, и ничего — о том, чего нет.
 * Поэтому запросы без результатов вынесены отдельно и стоят первыми: это
 * готовый список статей, которых не хватает.
 *
 * Виджет обычный, не TableWidget: строки — результат GROUP BY, а на таком
 * запросе у таблиц Filament ломается пагинация (см. TrafficSources).
 *
 * Живёт вне app/Filament/Widgets намеренно — см. PostViewsOverview.
 */
class SearchQueries extends Widget
{
    use InteractsWithPageFilters;

    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    protected static string $view = 'filament.analytics.widgets.search-queries';

    protected int|string|array $columnSpan = 'full';

    private const TOP_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // Граница считается один раз на все выборки: от трёх вызовов now() на
        // полуночном переходе запросы взяли бы разные окна (та же причина, что
        // в TrafficSources).
        $since = AnalyticsPeriod::startsAt($this->filters);

        return [
            'periodLabel' => AnalyticsPeriod::label($this->filters),
            'missing' => $this->top($since, onlyEmpty: true),
            'found' => $this->top($since, onlyEmpty: false),
            'total' => SearchQuery::query()->where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * Топ запросов за период.
     *
     * Ведро выбирается по МАКСИМУМУ найденного за период, а не по каждой
     * записи: иначе запрос, который сперва ничего не находил, а после
     * написанной статьи стал находить, висел бы в обоих списках сразу — и
     * закрытый пробел до трёх месяцев продолжал бы числиться дырой в контенте.
     *
     * @return Collection<int, object>
     */
    private function top(mixed $since, bool $onlyEmpty): Collection
    {
        return SearchQuery::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('query, COUNT(*) as times, MAX(results_count) as results')
            ->groupBy('query')
            ->havingRaw($onlyEmpty ? 'MAX(results_count) = 0' : 'MAX(results_count) > 0')
            ->orderByDesc('times')
            ->limit(self::TOP_LIMIT)
            ->get();
    }
}

<?php

namespace App\Filament\Analytics\Widgets;

use App\Models\PostView;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

/**
 * Просмотры по дням за выбранный период.
 *
 * Trend сам дорисовывает нулями дни, в которые просмотров не было
 * (mapValuesToDates), иначе провалы схлопывались бы и график врал о динамике.
 */
class PostViewsChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Просмотры по дням';

    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    /** @see PostViewsOverview::$pollingInterval */
    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $data = Trend::query(PostView::query())
            ->dateColumn('viewed_at')
            ->between(
                start: AnalyticsPeriod::startsAt($this->filters),
                end: CarbonImmutable::now()->endOfDay(),
            )
            ->perDay()
            ->count();

        return [
            'datasets' => [
                [
                    'label' => 'Просмотры',
                    'data' => $data->map(fn (TrendValue $value) => $value->aggregate),
                    // Цвет панели (Color::Amber из FilamentPanelProvider):
                    // Chart.js своего значения из темы Filament не берёт и без
                    // явного цвета рисует линию дефолтным серым.
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => 'start',
                    'tension' => 0.3,
                ],
            ],
            // Год в подписи не нужен: период не превышает квартала, а 90
            // подписей вида 2026-08-14 на оси не помещаются.
            'labels' => $data->map(fn (TrendValue $value) => CarbonImmutable::parse($value->date)->format('d.m')),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                // Без beginAtZero ось начинается от минимума выборки, и
                // колебание 40→45 выглядит как рост в разы.
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
            ],
        ];
    }
}

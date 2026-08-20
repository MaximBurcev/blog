<?php

namespace App\Filament\Pages;

use App\Filament\Analytics\Widgets\PostViewsChart;
use App\Filament\Analytics\Widgets\PostViewsOverview;
use App\Filament\Analytics\Widgets\PublishingPace;
use App\Filament\Analytics\Widgets\ReadingDepth;
use App\Filament\Analytics\Widgets\SearchQueries;
use App\Filament\Analytics\Widgets\TopPostsByViews;
use App\Filament\Analytics\Widgets\TrafficSources;
use App\Support\AnalyticsPeriod;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;

/**
 * Аналитика просмотров: во что превращается таблица post_views, которую до
 * сих пор писали, но нигде не читали — счётчик под заголовком статьи был
 * единственным её потребителем.
 *
 * Отдельная страница, а не блок на дашборде: дашборд отвечает на вопрос
 * «парсинг жив?», и подмешивать туда трафик значит смешать эксплуатацию с
 * контентом. Плюс общий для всех виджетов фильтр периода — на дашборде он
 * управлял бы заодно и виджетами парсинга.
 */
class Analytics extends Page
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Блог';

    protected static ?string $navigationLabel = 'Аналитика';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Аналитика просмотров';

    protected static string $view = 'filament.pages.analytics';

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Select::make('period')
                ->label('Период')
                ->options(AnalyticsPeriod::options())
                ->default(AnalyticsPeriod::DEFAULT_DAYS)
                // Пустое значение недопустимо: при сбросе фильтра в null
                // виджеты молча откатывались бы к периоду по умолчанию, а
                // подпись «За 30 дней» противоречила бы пустому селекту.
                ->selectablePlaceholder(false),
        ]);
    }

    /**
     * Виджеты идут после слота страницы (footer), чтобы фильтр периода стоял
     * над ними, а не под.
     */
    protected function getFooterWidgets(): array
    {
        return [
            PostViewsOverview::class,
            ReadingDepth::class,
            PostViewsChart::class,
            TrafficSources::class,
            PublishingPace::class,
            SearchQueries::class,
            TopPostsByViews::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|string|array
    {
        return 1;
    }

    /**
     * То, что страница передаёт в каждый виджет. Виджеты принимают это
     * свойством $filters (Filament\Widgets\Concerns\InteractsWithPageFilters),
     * помеченным #[Reactive] — без данных здесь смена периода перерисовывала
     * бы только сам селект.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'filters' => $this->filters,
        ];
    }
}

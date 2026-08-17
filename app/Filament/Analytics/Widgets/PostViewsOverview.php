<?php

namespace App\Filament\Analytics\Widgets;

use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Плитки трафика: сегодня, за выбранный период (с динамикой к предыдущему
 * такому же), уникальные читатели и накопленный итог.
 *
 * Живёт вне app/Filament/Widgets намеренно: та директория раздаётся
 * автодискавери панели (FilamentPanelProvider::discoverWidgets), и всё, что в
 * ней лежит, автоматически выводится на дашборде. Аналитике место на своей
 * странице, а дашборд остаётся про состояние парсинга.
 */
class PostViewsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    /**
     * Ленивую загрузку выключаем по той же причине, что и в
     * ParsingStatusOverview: в этой сборке панели она не отрабатывает и
     * виджет навсегда остаётся скелетоном.
     */
    protected static bool $isLazy = false;

    /**
     * Виджеты Filament по умолчанию опрашивают сервер каждые 5 секунд
     * (Concerns\CanPoll). Для накопительной статистики это бессмысленный
     * трафик: цифры за сутки не меняются на глазах, а каждый опрос — два
     * агрегата по самой быстрорастущей таблице блога.
     */
    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Просмотры';

    protected function getStats(): array
    {
        $counters = $this->counters();
        $periodLabel = AnalyticsPeriod::label($this->filters);

        $today = (int) ($counters->today ?? 0);
        $yesterday = (int) ($counters->yesterday ?? 0);
        $period = (int) ($counters->period ?? 0);
        $previous = (int) ($counters->previous ?? 0);
        $identified = (int) ($counters->identified ?? 0);
        $visitors = (int) ($counters->visitors ?? 0);

        return [
            Stat::make('Сегодня', (string) $today)
                ->description($this->comparison($today, $yesterday, 'вчера к этому же времени'))
                ->descriptionIcon($this->trendIcon($today, $yesterday))
                ->color($this->trendColor($today, $yesterday))
                ->icon('heroicon-m-eye'),

            Stat::make('За '.$periodLabel, (string) $period)
                ->description($this->comparison($period, $previous, 'в '.AnalyticsPeriod::previousLabel($this->filters)))
                ->descriptionIcon($this->trendIcon($period, $previous))
                ->color($this->trendColor($period, $previous))
                ->icon('heroicon-m-calendar-days'),

            Stat::make('Читателей за '.$periodLabel, (string) $visitors)
                // Знаменатель — только опознанные просмотры: просмотры без
                // сессии и без IP в число читателей не попали, и делить на них
                // общий счётчик значило бы завышать среднее.
                ->description($visitors > 0
                    ? 'Просмотров на читателя: '.number_format($identified / $visitors, 1, ',', ' ')
                    : 'Читателей за период не было')
                ->icon('heroicon-m-users')
                ->color('gray'),

            Stat::make('Всего просмотров', (string) $this->total())
                ->description('За всё время наблюдения')
                ->icon('heroicon-m-chart-bar')
                ->color('gray'),
        ];
    }

    /**
     * Все счётчики периода — одним проходом по индексу viewed_at.
     *
     * Раздельными запросами это пять сканов подряд по одному и тому же
     * диапазону (сегодня, вчера, период, предыдущий период, уникальные), тогда
     * как условная агрегация читает его один раз. Тот же приём, что в
     * ParsingStatusOverview::queueSnapshot.
     *
     * Уникальные считаются по session_hash с откатом на ip_hash: это
     * псевдонимы из PostViewService, восстановить по ним личность нельзя.
     * Просмотр, у которого нет ни того ни другого (запрос без сессии и без
     * определяемого IP), в число читателей не попадает, но в просмотрах
     * остаётся — иначе один неопознанный посетитель считался бы за
     * отдельного читателя. Метрика заведомо приблизительная и в другую
     * сторону: посетитель, часть визитов которого записана без сессии,
     * посчитается дважды — по сессии и по IP.
     *
     * Окна сравнения строятся одинаковой длины: и «сегодня против вчера», и
     * «период против предыдущего» обрезаются текущим временем суток. Иначе
     * незакрытые сутки сравнивались бы с полными и любая плитка утром
     * показывала бы падение, которого нет.
     */
    private function counters(): object
    {
        $periodStart = AnalyticsPeriod::startsAt($this->filters);
        $previousStart = AnalyticsPeriod::previousStartsAt($this->filters);
        $previousEnd = AnalyticsPeriod::previousEndsAt($this->filters);
        $now = CarbonImmutable::now();
        $todayStart = $now->startOfDay();
        $yesterdayStart = $todayStart->subDay();
        $yesterdayCutoff = $now->subDay();

        return DB::table('post_views')
            ->selectRaw('
                SUM(CASE WHEN viewed_at >= ? THEN 1 ELSE 0 END) as today,
                SUM(CASE WHEN viewed_at >= ? AND viewed_at < ? THEN 1 ELSE 0 END) as yesterday,
                SUM(CASE WHEN viewed_at >= ? THEN 1 ELSE 0 END) as period,
                SUM(CASE WHEN viewed_at >= ? AND COALESCE(session_hash, ip_hash) IS NOT NULL THEN 1 ELSE 0 END) as identified,
                SUM(CASE WHEN viewed_at >= ? AND viewed_at < ? THEN 1 ELSE 0 END) as previous,
                COUNT(DISTINCT CASE WHEN viewed_at >= ? THEN COALESCE(session_hash, ip_hash) END) as visitors
            ', [
                $todayStart,
                $yesterdayStart, $yesterdayCutoff,
                $periodStart,
                $periodStart,
                $previousStart, $previousEnd,
                $periodStart,
            ])
            // Нижняя граница выборки — начало предыдущего периода: дальше в
            // прошлое ни одна из плиток не смотрит, а без этого условия
            // условная агрегация прочитала бы всю таблицу.
            ->where('viewed_at', '>=', $previousStart)
            // Запрос идёт мимо Eloquent, поэтому глобальный скоуп
            // PostView::HUMANS_ONLY здесь не применяется — условие ставим сами.
            ->where('is_bot', false)
            ->first() ?? (object) [];
    }

    /**
     * Просмотры за всё время.
     *
     * Единственная цифра страницы, которую нельзя ограничить по viewed_at, то
     * есть единственный полный проход по индексу — InnoDB готового счётчика
     * строк не держит. За минуту она заметно не меняется, а пересчитывалась бы
     * на каждую смену периода, поэтому берётся из кэша.
     */
    private function total(): int
    {
        return Cache::remember(
            // Версия в ключе: смысл значения изменился (теперь без роботов), а
            // старое пролежало бы в кэше ещё 10 минут после выката и разошлось
            // бы с плиткой периода на глазах у админа.
            'analytics:post-views-total:humans',
            now()->addMinutes(10),
            // Мимо Eloquent, поэтому фильтр роботов явный — см. counters().
            fn (): int => DB::table('post_views')->where('is_bot', false)->count(),
        );
    }

    private function comparison(int $current, int $previous, string $subject): string
    {
        if ($previous === 0) {
            return $current > 0
                ? 'Сравнивать не с чем: '.$subject.' просмотров не было'
                : 'Просмотров нет';
        }

        $delta = (int) round(($current - $previous) / $previous * 100);

        if ($delta === 0) {
            return 'Столько же, сколько '.$subject;
        }

        return sprintf('%s%d%% к тому, что было %s', $delta > 0 ? '+' : '−', abs($delta), $subject);
    }

    private function trendIcon(int $current, int $previous): ?string
    {
        if ($current === $previous) {
            return null;
        }

        return $current > $previous ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
    }

    private function trendColor(int $current, int $previous): string
    {
        return match (true) {
            $current > $previous => 'success',
            $current < $previous => 'danger',
            default => 'gray',
        };
    }
}

<?php

namespace App\Filament\Analytics\Widgets;

use App\Support\AnalyticsPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Ретеншн: сколько читателей возвращаются на другой день.
 *
 * Весь остальной трафик измеряется просмотрами, но растущий счётчик просмотров
 * одинаково означает и «статью открыли сто новых людей», и «один и тот же
 * человек обновляет страницу». Эта плитка отличает одно от другого: читатель
 * считается вернувшимся, если у него просмотры минимум в два разных дня.
 *
 * Живёт вне app/Filament/Widgets намеренно — см. PostViewsOverview.
 */
class ReaderRetention extends BaseWidget
{
    use InteractsWithPageFilters;

    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    /** @see PostViewsOverview::$pollingInterval */
    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Возвраты читателей';

    protected function getStats(): array
    {
        $periodLabel = AnalyticsPeriod::label($this->filters);

        $row = $this->counters();
        $readers = (int) ($row->readers ?? 0);
        $returned = (int) ($row->returned ?? 0);

        return [
            Stat::make('Вернулись на другой день', (string) $returned)
                ->description($readers > 0
                    ? 'Из '.$readers.' читателей за '.$periodLabel
                    : 'Читателей за '.$periodLabel.' не было')
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('gray'),

            Stat::make('Доля вернувшихся', $readers > 0
                ? round($returned / $readers * 100).'%'
                : '—')
                // Ноль процентов и «некого считать» — разные утверждения, и
                // первое выглядело бы убедительно на пустом периоде.
                ->description($readers > 0
                    ? 'Просмотры минимум в два разных дня'
                    : 'Делить не на что')
                ->icon('heroicon-m-users')
                ->color('gray'),
        ];
    }

    /**
     * Читатель — тот же псевдоним, что в PostViewsOverview: session_hash с
     * откатом на ip_hash (у просмотра без сессии session_hash = NULL, и без
     * COALESCE такой посетитель выпадал бы из метрики). Просмотры без обоих
     * хэшей не считаем читателями, роботов отсекаем.
     */
    private function counters(): object
    {
        $since = AnalyticsPeriod::startsAt($this->filters);

        $perReader = DB::table('post_views')
            ->selectRaw('COALESCE(session_hash, ip_hash) as reader')
            ->selectRaw('COUNT(DISTINCT DATE(viewed_at)) as active_days')
            ->where('viewed_at', '>=', $since)
            // Мимо Eloquent, глобальный скоуп PostView::HUMANS_ONLY не
            // применяется — условие ставим сами, как в PostViewsOverview.
            ->where('is_bot', false)
            ->whereRaw('COALESCE(session_hash, ip_hash) IS NOT NULL')
            ->groupBy('reader');

        return DB::query()
            ->fromSub($perReader, 'readers')
            ->selectRaw('COUNT(*) as readers, SUM(active_days >= 2) as returned')
            ->first() ?? (object) [];
    }
}

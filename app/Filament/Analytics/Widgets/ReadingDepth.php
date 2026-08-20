<?php

namespace App\Filament\Analytics\Widgets;

use App\Models\PostView;
use App\Support\AnalyticsPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Сколько разных статей успевает прочитать один читатель.
 *
 * Соседний виджет показывает среднее «просмотров на читателя», но среднее здесь
 * обманчиво: на 20.08.2026 оно равно 1.4, тогда как из 735 читателей больше
 * одной статьи открыли всего 23 — три процента. Средние поднимает горстка
 * читающих помногу, а типичный читатель уходит с первой же страницы.
 *
 * Цифра оценивает не трафик, а связность блога: работают ли «Похожие посты»,
 * теги и категории. Прирост тут дешевле привлечения — человек уже пришёл, и
 * вопрос лишь в том, найдёт ли он вторую статью.
 *
 * Единица счёта — читатель за период, а не «визит»: post_views не хранит
 * границ сессии, и склеить просмотры в посещения нечем. Отсюда и ограничение —
 * на квартальном окне один человек, зашедший в июне и в августе, считается
 * одним читателем с глубиной 2.
 *
 * Живёт вне app/Filament/Widgets намеренно — см. PostViewsOverview.
 */
class ReadingDepth extends BaseWidget
{
    use InteractsWithPageFilters;

    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    /** @see PostViewsOverview::$pollingInterval */
    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Глубина чтения';

    /** Доля читателей, идущих дальше первой статьи, ниже которой это проблема. */
    private const HEALTHY_SHARE = 20;

    protected function getStats(): array
    {
        $depth = $this->depth();
        $periodLabel = AnalyticsPeriod::label($this->filters);

        $readers = (int) ($depth->readers ?? 0);

        if ($readers === 0) {
            return [
                Stat::make('Читателей за '.$periodLabel, '0')
                    ->description('За период никто не читал')
                    ->icon('heroicon-m-book-open')
                    ->color('gray'),
            ];
        }

        $single = (int) ($depth->single ?? 0);
        $multiple = $readers - $single;
        $share = (int) round($multiple / $readers * 100);
        $color = $share >= self::HEALTHY_SHARE ? 'success' : 'warning';

        return [
            // Считаем один процент и выводим его дополнение: два независимых
            // округления давали бы в сумме 101%.
            Stat::make('Читают только одну статью', (100 - $share).'%')
                ->description($single.' из '.$readers.' читателей за '.$periodLabel)
                ->icon('heroicon-m-arrow-right-start-on-rectangle')
                ->color($color),

            Stat::make('Идут дальше первой', $share.'%')
                // Сравнивать не с чем: исторической нормы у блога нет, а
                // отраслевые ориентиры при полусотне визитов в день
                // бессмысленны. Поэтому абсолютные числа, а не динамика.
                ->description($multiple.' читателей открыли больше одной статьи')
                ->icon('heroicon-m-arrows-right-left')
                ->color($color),

            Stat::make('Самый глубокий читатель', (string) (int) ($depth->deepest ?? 0))
                ->description('Статей за '.$periodLabel)
                ->icon('heroicon-m-book-open')
                ->color('gray'),
        ];
    }

    /**
     * Все три числа — одним проходом, без выгрузки строки на читателя.
     *
     * Внутренний запрос считает РАЗНЫЕ статьи (COUNT DISTINCT post_id), а не
     * просмотры: дедуп в PostViewService держится 24 часа, а окно виджета —
     * до 90 дней, и повторный заход на ту же статью через неделю создаёт вторую
     * запись. По COUNT(*) такой читатель попадал бы в «идут дальше первой»,
     * завышая ровно ту долю, которую виджет измеряет.
     *
     * Читатель опознаётся как и везде в аналитике — по session_hash с откатом
     * на ip_hash; оба псевдонимы. Просмотры без обоих не учитываются: приписать
     * их какому-то читателю не к чему.
     */
    private function depth(): object
    {
        // toBase() важен: он применяет глобальный скоуп PostView::HUMANS_ONLY,
        // то есть роботы отсеиваются до группировки.
        $perReader = PostView::query()
            ->where('viewed_at', '>=', AnalyticsPeriod::startsAt($this->filters))
            ->whereRaw('COALESCE(session_hash, ip_hash) IS NOT NULL')
            ->selectRaw('COALESCE(session_hash, ip_hash) as reader, COUNT(DISTINCT post_id) as depth')
            ->groupBy('reader')
            ->toBase();

        return DB::query()
            ->fromSub($perReader, 'readers')
            ->selectRaw('COUNT(*) as readers, SUM(depth = 1) as single, MAX(depth) as deepest')
            ->first() ?? (object) [];
    }
}

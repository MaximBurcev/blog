<?php

namespace App\Filament\Analytics\Widgets;

use App\Models\PostView;
use App\Service\PostViewService;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Откуда пришли читатели: домены-источники и метки кампаний за период.
 *
 * До 17.08.2026 ответа на этот вопрос не существовало в принципе — реферер
 * нигде не сохранялся, а access-логи Apache недоступны из приложения. Записи
 * старше PostView::ATTRIBUTION_SINCE в отчёт не берутся: у них источник не
 * «прямой заход», а «неизвестно», и в сумме они бы этот отчёт и составили.
 *
 * Виджет обычный, не TableWidget: строки здесь — результат GROUP BY, а у
 * таблиц Filament на таком запросе ломается пагинация (Laravel считает общее
 * число строк тем же агрегатом и получает не число, а набор групп; той же
 * грабли касается комментарий в TopPostsByViews про having). Источников
 * десятки, поэтому вместо пагинации — топ и строка «прочие».
 */
class TrafficSources extends Widget
{
    use InteractsWithPageFilters;

    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    protected static string $view = 'filament.analytics.widgets.traffic-sources';

    protected int|string|array $columnSpan = 'full';

    /** Сколько доменов показываем поимённо, прежде чем свернуть остальное. */
    private const TOP_LIMIT = 10;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // Граница считается один раз на все выборки виджета: от трёх вызовов
        // now() на полуночном переходе запросы взяли бы разные окна.
        $since = $this->since();
        $rows = $this->groupedByReferer($since);
        $own = $this->ownHost();

        $external = $rows
            ->filter(fn (object $row): bool => $row->referer_host !== null && $row->referer_host !== $own)
            ->values();

        return [
            'periodLabel' => AnalyticsPeriod::label($this->filters),
            'truncated' => $since->gt(AnalyticsPeriod::startsAt($this->filters)),
            'since' => $since,
            'top' => $external->take(self::TOP_LIMIT),
            'restViews' => $external->skip(self::TOP_LIMIT)->sum('views'),
            'restDomains' => max(0, $external->count() - self::TOP_LIMIT),
            'internal' => $this->row($rows->firstWhere('referer_host', $own)),
            'direct' => $this->row($rows->firstWhere('referer_host', null)),
            'campaigns' => $this->campaigns($since),
        ];
    }

    /**
     * Нижняя граница выборки — позднее из двух: начало выбранного периода и
     * дата, с которой реферер вообще пишется.
     */
    private function since(): CarbonImmutable
    {
        $periodStart = CarbonImmutable::parse(AnalyticsPeriod::startsAt($this->filters));
        $attributionStart = CarbonImmutable::parse(PostView::ATTRIBUTION_SINCE)->startOfDay();

        return $periodStart->gt($attributionStart) ? $periodStart : $attributionStart;
    }

    /**
     * Один проход вместо отдельных запросов на источники, внутренние переходы
     * и прямые заходы: NULL в GROUP BY — такая же группа, как остальные.
     *
     * @return Collection<int, object>
     */
    private function groupedByReferer(CarbonImmutable $since): Collection
    {
        return PostView::query()
            ->where('viewed_at', '>=', $since)
            ->selectRaw('referer_host, COUNT(*) as views, COUNT(DISTINCT COALESCE(session_hash, ip_hash)) as visitors')
            ->groupBy('referer_host')
            ->orderByDesc('views')
            ->get();
    }

    /**
     * Хост собственного сайта в том же виде, в каком его пишет
     * PostViewService: сравнивать нормализованное с ненормализованным значит
     * не найти совпадения и объявить внутренние переходы внешним источником.
     */
    private function ownHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) ? PostViewService::normalizeHost($host) : null;
    }

    /**
     * @return array{views: int, visitors: int}
     */
    private function row(?object $row): array
    {
        return [
            'views' => (int) ($row->views ?? 0),
            'visitors' => (int) ($row->visitors ?? 0),
        ];
    }

    /**
     * Метки кампаний. Пока их никто не проставляет, поэтому блок в шаблоне
     * скрыт до первой размеченной ссылки — пустая таблица «UTM» на странице
     * только занимала бы место.
     *
     * @return Collection<int, object>
     */
    private function campaigns(CarbonImmutable $since): Collection
    {
        return PostView::query()
            ->whereNotNull('utm_source')
            ->where('viewed_at', '>=', $since)
            ->selectRaw('utm_source, utm_medium, utm_campaign, COUNT(*) as views')
            ->groupBy('utm_source', 'utm_medium', 'utm_campaign')
            ->orderByDesc('views')
            ->limit(self::TOP_LIMIT)
            ->get();
    }
}

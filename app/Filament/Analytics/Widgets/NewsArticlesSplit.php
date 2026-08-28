<?php

namespace App\Filament\Analytics\Widgets;

use App\Models\Post;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Разрез новости/статьи: чего из двух лент мы публикуем больше.
 *
 * Новости и статьи — две ленты одного типа записей (Post::is_news, скоупы
 * news()/articles()), и без раздельных цифр темп публикаций из PublishingPace
 * смешивает их в одну кучу.
 *
 * Живёт вне app/Filament/Widgets намеренно — см. PostViewsOverview.
 */
class NewsArticlesSplit extends BaseWidget
{
    use InteractsWithPageFilters;

    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    /** @see PostViewsOverview::$pollingInterval */
    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'Статьи и новости';

    protected function getStats(): array
    {
        $since = AnalyticsPeriod::startsAt($this->filters);
        $periodLabel = AnalyticsPeriod::label($this->filters);

        return [
            Stat::make('Статей опубликовано', (string) Post::published()->articles()->count())
                ->description('За '.$periodLabel.': '.$this->publishedSince(false, $since))
                ->icon('heroicon-m-document-text')
                ->color('gray'),

            Stat::make('Новостей опубликовано', (string) Post::published()->news()->count())
                ->description('За '.$periodLabel.': '.$this->publishedSince(true, $since))
                ->icon('heroicon-m-newspaper')
                ->color('gray'),
        ];
    }

    /**
     * Сколько из опубликованных вышло внутри выбранного периода. По
     * published_at, как в PublishingPace: created_at хранит дату оригинала
     * на стороне источника и к нашему темпу отношения не имеет.
     */
    private function publishedSince(bool $news, CarbonImmutable $since): int
    {
        return Post::published()
            ->when($news, fn ($q) => $q->news(), fn ($q) => $q->articles())
            ->where('published_at', '>=', $since)
            ->count();
    }
}

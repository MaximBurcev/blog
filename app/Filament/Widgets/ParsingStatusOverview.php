<?php

namespace App\Filament\Widgets;

use App\Models\Post;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Состояние парсинга: сколько статей разбирается прямо сейчас, сколько ждёт
 * в очереди, когда последний раз что-то доразобралось и сколько накопилось
 * ошибок. Раньше ответ на вопрос «парсинг вообще идёт?» можно было получить
 * только из laravel.log или запросом в таблицу jobs.
 *
 * Обновляется сам раз в 10 секунд (pollingInterval), чтобы страницу не
 * приходилось перезагружать вручную.
 */
class ParsingStatusOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    /**
     * Рендерим сразу вместе со страницей, без ленивой подгрузки: она в этой
     * сборке панели не отрабатывает — виджет остаётся скелетоном (тем же
     * болеет и BlogPostsChart). Запросы тут дешёвые: четыре count по
     * маленькой таблице jobs плюс два по posts.
     */
    protected static bool $isLazy = false;

    /**
     * Задачи ждут дольше этого времени и ни одна не взята в работу —
     * повод предупредить, что воркер очереди, похоже, не запущен. Порог, а не
     * «pending > 0», потому что между задачами воркер спит (--sleep=3) и
     * мгновенный снимок очереди почти всегда застаёт кого-то в ожидании.
     */
    private const STALE_QUEUE_SECONDS = 120;

    protected function getStats(): array
    {
        $running = $this->countJobs(fn (Builder $q) => $q->whereNotNull('reserved_at'));
        $pendingPosts = $this->countJobs(fn (Builder $q) => $q->whereNull('reserved_at'), 'StorePostJob');
        $pendingReleases = $this->countJobs(fn (Builder $q) => $q->whereNull('reserved_at'), 'ParseReleaseJob');

        return [
            $this->runningStat($running, $pendingPosts + $pendingReleases),
            $this->queueStat($pendingPosts, $pendingReleases, $running),
            $this->lastParsedStat(),
            $this->errorsStat(),
        ];
    }

    private function runningStat(int $running, int $pending): Stat
    {
        $stat = Stat::make('Сейчас парсится', $running > 0 ? $running.' '.$this->plural($running, 'задача', 'задачи', 'задач') : 'нет')
            ->icon('heroicon-m-arrow-path');

        if ($running > 0) {
            return $stat
                ->description('Воркер разбирает статьи, страницу можно не обновлять')
                ->color('success');
        }

        return $stat
            ->description($pending > 0 ? 'Задачи есть, но ни одна не взята в работу' : 'Очередь пуста — парсинг не идёт')
            ->color($pending > 0 ? 'warning' : 'gray');
    }

    private function queueStat(int $pendingPosts, int $pendingReleases, int $running): Stat
    {
        $description = $pendingReleases > 0
            ? "Плюс дайджестов на разбор: {$pendingReleases}"
            : 'Дайджестов на разбор нет';

        $color = $pendingPosts + $pendingReleases > 0 ? 'primary' : 'gray';

        $waitingSince = $this->oldestPendingAt();

        if ($waitingSince !== null && $running === 0 && $waitingSince->diffInSeconds(now()) > self::STALE_QUEUE_SECONDS) {
            $description = 'Ждут с '.$waitingSince->format('H:i').' и никто не взял — возможно, воркер очереди не запущен';
            $color = 'danger';
        }

        return Stat::make('Ждут в очереди', $pendingPosts.' '.$this->plural($pendingPosts, 'статья', 'статьи', 'статей'))
            ->description($description)
            ->icon('heroicon-m-queue-list')
            ->color($color);
    }

    private function lastParsedStat(): Stat
    {
        $last = Post::whereNotNull('parsed_at')->latest('parsed_at')->first();

        if ($last === null) {
            return Stat::make('Последний разбор', 'не было')
                ->description('Ни один пост ещё не проходил через парсер')
                ->icon('heroicon-m-clock')
                ->color('gray');
        }

        return Stat::make('Последний разбор', $last->parsed_at->diffForHumans())
            ->description(Str::limit($last->title, 60))
            ->icon('heroicon-m-clock')
            ->color($last->hasParseError() ? 'warning' : 'success');
    }

    private function errorsStat(): Stat
    {
        $failedPosts = Post::parseFailed()->count();
        $failedJobs = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return Stat::make('Постов с ошибкой', (string) $failedPosts)
            ->description($failedJobs > 0
                ? "Упавших задач за сутки: {$failedJobs}"
                : 'Упавших задач за сутки нет')
            ->icon('heroicon-m-exclamation-triangle')
            ->color($failedPosts > 0 || $failedJobs > 0 ? 'danger' : 'gray');
    }

    /**
     * Задачи парсинга в очереди. Тип задачи определяется по payload: имя
     * класса лежит в нём и как displayName, и внутри сериализованной
     * команды — LIKE по нему надёжнее, чем разбор JSON в SQL.
     */
    private function countJobs(callable $filter, ?string $jobClass = null): int
    {
        return $this->jobsQuery($jobClass)->where($filter)->count();
    }

    private function oldestPendingAt(): ?Carbon
    {
        $timestamp = $this->jobsQuery()->whereNull('reserved_at')->min('available_at');

        return $timestamp === null ? null : Carbon::createFromTimestamp($timestamp);
    }

    private function jobsQuery(?string $jobClass = null): Builder
    {
        $connection = config('queue.connections.database.connection');

        return DB::connection($connection)
            ->table(config('queue.connections.database.table', 'jobs'))
            ->where(function (Builder $query) use ($jobClass) {
                foreach ($jobClass !== null ? [$jobClass] : ['StorePostJob', 'ParseReleaseJob'] as $class) {
                    $query->orWhere('payload', 'like', '%'.$class.'%');
                }
            });
    }

    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }

        if (in_array($mod10, [2, 3, 4], true) && ! in_array($mod100, [12, 13, 14], true)) {
            return $few;
        }

        return $many;
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\Post;
use App\Service\Translation\FallbackTranslator;
use App\Service\Translation\GeminiTranslator;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
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
     * болеет и BlogPostsChart). Запросов немного: один агрегат по jobs
     * (см. queueSnapshot) плюс три по posts/failed_jobs.
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
        $queue = $this->queueSnapshot();

        return [
            $this->runningStat($queue['running'], $queue['pending_posts'] + $queue['pending_releases']),
            $this->queueStat($queue['pending_posts'], $queue['pending_releases'], $queue['running'], $queue['oldest_pending_at']),
            $this->lastParsedStat(),
            $this->errorsStat(),
            $this->translatorStat(),
        ];
    }

    /**
     * Жив ли переводчик.
     *
     * Отказ LLM — единственная поломка конвейера, которая ничего не ломает
     * видимо: очередь идёт, статьи выходят, просто переведены они машинным
     * скрейпером вместо модели. Узнать об этом можно было только из laravel.log
     * или по колонке «Движок» в списке постов, то есть постфактум и вручную —
     * ровно тот сценарий, в котором OCR полгода не работал незамеченным.
     *
     * Плитка живёт здесь, а не на странице аналитики, по той же логике, по
     * которой здесь стоит состояние очереди: это вопрос «конвейер жив?», а не
     * «сколько мы потратили». К тому же виджет опрашивается каждые 10 секунд, а
     * размыкатель держится 5 минут — авария попадает на глаза, пока она идёт.
     */
    private function translatorStat(): Stat
    {
        [$value, $description, $color] = $this->translatorState();

        return Stat::make('Переводчик', $value)
            ->description($description)
            ->icon('heroicon-m-language')
            ->color($color);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function translatorState(): array
    {
        if (config('translation.driver') === 'google') {
            return ['скрейпер', 'Модель отключена настройкой TRANSLATION_DRIVER', 'gray'];
        }

        // Запасной движок подставляется только при включённом fallback
        // (AppServiceProvider). Без него отказ модели означает не «переведёт
        // скрейпер», а «статья останется непереведённой», и обещать откат
        // здесь было бы прямым враньём.
        $fallback = (bool) config('translation.fallback');
        $consequence = $fallback ? 'статьи переводит скрейпер' : 'статьи остаются без перевода';

        if ((string) config('translation.gemini.key') === '') {
            return ['не работает', 'Не задан GEMINI_API_KEY — '.$consequence, 'danger'];
        }

        // Квота у Google своя на каждую модель, поэтому и предохранитель свой:
        // спрашивать надо про всю цепочку, иначе исчерпанная первая модель
        // выглядела бы полной аварией, хотя перевод спокойно идёт следующей.
        // Список берём у самого движка, а не собираем из конфига здесь: имя,
        // под которым размыкается предохранитель, знает только он, и вторая
        // копия сборки уже расходилась с первой.
        $models = GeminiTranslator::chainModels();
        $alive = array_values(array_filter($models, fn (string $m): bool => ! FallbackTranslator::isDown($m)));

        if ($alive === []) {
            return ['не работает', 'Квота исчерпана у всех моделей — '.$consequence, 'danger'];
        }

        $current = $alive[0];

        if ($current !== ($models[0] ?? $current)) {
            return ['запасная модель', $current.' — у основной кончилась квота', 'warning'];
        }

        return ['модель', $current, 'success'];
    }

    /**
     * Один проход по jobs вместо пяти.
     *
     * Виджет обновляется каждые 10 секунд, и раньше на каждое обновление
     * уходило четыре COUNT и один MIN, каждый — с `payload LIKE '%…%'` по
     * LONGTEXT-колонке, то есть пять полных сканов таблицы очереди подряд.
     * Все нужные числа снимаются условной агрегацией за один запрос.
     *
     * Разбирать тип задачи всё ещё приходится по payload: имя класса лежит
     * только там. Уйти от этого можно, разведя парсинг по именованным
     * очередям, но это требует согласованной смены команды запуска воркера
     * на сервере — иначе задачи просто перестанут разбираться.
     *
     * @return array{running: int, pending_posts: int, pending_releases: int, oldest_pending_at: ?Carbon}
     */
    private function queueSnapshot(): array
    {
        $connection = config('queue.connections.database.connection');
        $table = config('queue.connections.database.table', 'jobs');

        $row = DB::connection($connection)->table($table)
            ->selectRaw('
                SUM(reserved_at IS NOT NULL) as running,
                SUM(reserved_at IS NULL AND payload LIKE ?) as pending_posts,
                SUM(reserved_at IS NULL AND payload LIKE ?) as pending_releases,
                MIN(CASE WHEN reserved_at IS NULL THEN available_at END) as oldest_pending
            ', ['%StorePostJob%', '%ParseReleaseJob%'])
            ->first();

        return [
            'running' => (int) ($row->running ?? 0),
            'pending_posts' => (int) ($row->pending_posts ?? 0),
            'pending_releases' => (int) ($row->pending_releases ?? 0),
            'oldest_pending_at' => isset($row->oldest_pending)
                ? Carbon::createFromTimestamp($row->oldest_pending)
                : null,
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

    private function queueStat(int $pendingPosts, int $pendingReleases, int $running, ?Carbon $waitingSince): Stat
    {
        $description = $pendingReleases > 0
            ? "Плюс дайджестов на разбор: {$pendingReleases}"
            : 'Дайджестов на разбор нет';

        $color = $pendingPosts + $pendingReleases > 0 ? 'primary' : 'gray';

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

<?php

namespace App\Filament\Analytics\Widgets;

use App\Models\Post;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Темп: сколько постов появилось у парсера и сколько из них дошло до читателя.
 *
 * Остальная аналитика отвечает на вопрос «что читают». Этот виджет — про то,
 * что мы вообще делаем, и он существует, потому что главное узкое место блога
 * не в трафике: на 20.08.2026 опубликовано 28 постов при 111 разобранных и
 * ждущих в очереди, а разрыв растёт примерно на десяток в неделю. Пока цифра не
 * висит перед глазами, очередь копится незаметно.
 *
 * Живёт вне app/Filament/Widgets намеренно — см. PostViewsOverview.
 */
class PublishingPace extends BaseWidget
{
    /** @see PostViewsOverview::$isLazy */
    protected static bool $isLazy = false;

    /** @see PostViewsOverview::$pollingInterval */
    protected static ?string $pollingInterval = null;

    use InteractsWithPageFilters;

    protected ?string $heading = 'Публикации';

    /**
     * Дата, с которой posts.published_at заполняется по-настоящему.
     *
     * Историческим записям миграция проставила updated_at — приближение, а не
     * факт. Показывать по нему темп прошлых недель значит выдавать догадку за
     * измерение, поэтому периоды до этой даты помечаются как неполные.
     */
    private const PUBLISHED_AT_SINCE = '2026-08-20';

    protected function getStats(): array
    {
        $since = AnalyticsPeriod::startsAt($this->filters);
        $periodLabel = AnalyticsPeriod::label($this->filters);

        /*
         * Считаем по parsed_at, а НЕ по created_at: последний хранит дату
         * публикации оригинала на стороне источника (StorePostJob вытаскивает
         * её со страницы, см. Post::$fillable). На проде 135 постов из 147
         * разобраны позже своего created_at, максимальный разрыв — 150 дней:
         * мартовская статья попала в блог в августе. По created_at виджет
         * показывал бы, когда материал вышел у автора, выдавая это за темп
         * нашего парсера.
         */
        $parsed = Post::query()->where('parsed_at', '>=', $since)->count();
        $published = Post::query()->where('published_at', '>=', $since)->count();

        /*
         * «Не сломанные», а не «ровно ok»: parse_status появился 30.07.2026 и
         * колонка nullable без default, поэтому у постов старше этой даты и у
         * всего, что заводят руками в админке (форма его не задаёт), там NULL.
         * Условие `= ok` молча выкидывало их из очереди — при том что виджет
         * заявлен как её состояние.
         */
        $queue = Post::query()
            ->where('published', false)
            ->where(fn ($q) => $q->whereNull('parse_status')->orWhere('parse_status', '!=', Post::PARSE_STATUS_FAILED))
            ->count();

        $failed = Post::query()->parseFailed()->where('published', false)->count();

        return [
            Stat::make('Опубликовано за '.$periodLabel, (string) $published)
                ->description($this->publishedNote($since, $parsed, $published))
                ->icon('heroicon-m-paper-airplane')
                ->color(match (true) {
                    $parsed === 0 && $published === 0 => 'gray',
                    $published >= $parsed => 'success',
                    default => 'warning',
                }),

            Stat::make('Разобрано парсером', (string) $parsed)
                // «Разборов», а не «новых постов»: parsed_at переписывается
                // при повторном разборе (кнопка «Перепарсить»), и старый пост,
                // прогнанный заново, снова попадает в период.
                ->description('Разборов за '.$periodLabel)
                ->icon('heroicon-m-inbox-arrow-down')
                ->color('gray'),

            Stat::make('Ждут публикации', (string) $queue)
                // Не за период, а всего: очередь — это состояние, а не поток.
                ->description($this->queueNote($queue, $published, $since))
                ->icon('heroicon-m-queue-list')
                ->color($queue > 50 ? 'danger' : ($queue > 10 ? 'warning' : 'success')),

            Stat::make('С ошибкой парсинга', (string) $failed)
                ->description('Заглушки без текста — их публиковать нечем')
                ->icon('heroicon-m-exclamation-triangle')
                ->color($failed > 0 ? 'warning' : 'gray'),
        ];
    }

    private function publishedNote(CarbonImmutable $since, int $parsed, int $published): string
    {
        if ($since->lt(CarbonImmutable::parse(self::PUBLISHED_AT_SINCE))) {
            return 'Часть периода — до учёта даты публикации, цифра неполная';
        }

        if ($parsed === 0) {
            return 'Парсер за период ничего не принёс';
        }

        return $published >= $parsed
            ? 'Очередь разбирается быстрее, чем пополняется'
            : 'Из '.$parsed.' разобранных — очередь растёт';
    }

    /**
     * Сколько недель уйдёт на разбор очереди при нынешнем темпе. Абстрактное
     * «111 в очереди» ни о чём не говорит, а «при таком темпе — до весны»
     * говорит вполне.
     */
    private function queueNote(int $queue, int $published, CarbonImmutable $since): string
    {
        if ($queue === 0) {
            return 'Очередь пуста';
        }

        $days = max(1, $since->diffInDays(CarbonImmutable::now()));
        $perDay = $published / $days;

        if ($perDay <= 0) {
            return 'При нынешнем темпе — не разберётся никогда';
        }

        $weeks = (int) ceil($queue / $perDay / 7);

        return 'При нынешнем темпе разбирать '.$weeks.' нед.';
    }
}

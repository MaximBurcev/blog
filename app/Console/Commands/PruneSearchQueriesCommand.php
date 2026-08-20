<?php

namespace App\Console\Commands;

use App\Models\SearchQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Чистка журнала поисковых запросов.
 *
 * Аналитика дальше квартала не смотрит (App\Support\AnalyticsPeriod — 7/30/90
 * дней), то есть всё старше полугода не читает ни один интерфейс. Хранить это
 * дальше — не польза, а ответственность: строки ежедневно уезжают в архив
 * backup:run, а текст запроса чувствительнее адреса открытой страницы.
 *
 * Записи удаляются целиком, а не обезличиваются: обезличивать в них нечего —
 * ни IP, ни сессии там нет по построению.
 */
class PruneSearchQueriesCommand extends Command
{
    protected $signature = 'search-queries:prune
        {--days=180 : Сколько дней хранить журнал}
        {--dry-run : Показать, сколько будет удалено, без записи}';

    protected $description = 'Удаляет старые записи журнала поиска по сайту';

    public function handle(): int
    {
        $days = $this->option('days');

        // Явная проверка, а не max(1, (int) …): приведение типа молча
        // превращает опечатку в «оставить один день», то есть в удаление почти
        // всего (ср. PrunePostViewsCommand).
        if (! ctype_digit((string) $days) || (int) $days < 1) {
            $this->error('--days должен быть целым числом больше нуля.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays((int) $days)->startOfDay();
        $query = SearchQuery::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("Запросов старше {$cutoff->toDateString()} нет.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Будет удалено {$count} запросов старше {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Удалено {$deleted} запросов старше {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}

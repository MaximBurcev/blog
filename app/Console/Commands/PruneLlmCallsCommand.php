<?php

namespace App\Console\Commands;

use App\Models\LlmCall;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Чистка журнала вызовов модели.
 *
 * Аналитика дальше квартала не смотрит (App\Support\AnalyticsPeriod — 7/30/90
 * дней), так что всё старше полугода не читает ни один интерфейс, зато каждая
 * строка ежедневно уезжает в архив backup:run.
 *
 * Год — соблазнительный срок «чтобы сравнить с прошлым сезоном», но сравнивать
 * будет не с чем: цена и версия модели за год сменятся, а пересчитывать старые
 * токены по новому прайсу — это выдумывать историю, которой не было.
 */
class PruneLlmCallsCommand extends Command
{
    protected $signature = 'llm-calls:prune
        {--days=180 : Сколько дней хранить журнал}
        {--dry-run : Показать, сколько будет удалено, без записи}';

    protected $description = 'Удаляет старые записи журнала вызовов языковой модели';

    public function handle(): int
    {
        $days = $this->option('days');

        // Явная проверка, а не max(1, (int) …): приведение типа молча
        // превращает опечатку в «оставить один день», то есть в удаление почти
        // всего (ср. PruneSearchQueriesCommand).
        if (! ctype_digit((string) $days) || (int) $days < 1) {
            $this->error('--days должен быть целым числом больше нуля.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays((int) $days)->startOfDay();
        $query = LlmCall::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("Вызовов старше {$cutoff->toDateString()} нет.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Будет удалено {$count} вызовов старше {$cutoff->toDateString()}.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Удалено {$deleted} вызовов старше {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}

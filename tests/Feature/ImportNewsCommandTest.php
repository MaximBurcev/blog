<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Импорт новостей: страж расписания.
 *
 * Команда осталась и работает — снят только её ежедневный запуск. Разница
 * принципиальная и ничем, кроме этого теста, не закреплена: вернуть строку
 * `$schedule->command('news:import')` в Kernel можно одним движением, а
 * последствия увидит только тот, кто откроет виджет расхода на перевод.
 *
 * Цена возврата известна поимённо. NewsImportService намеренно перезапускает
 * заглушки (parse_status = failed), потолка у повторов нет, а часть ссылок
 * секции — ролики YouTube и страницы релизов, где статьи не существует. 24.08
 * все 11 вызовов модели за сутки пришлись на такие заглушки: 55% бесплатной
 * квоты каждое утро уходило на страницы, которые не разберутся никогда.
 *
 * Тот же приём, что в PrunePostViewsCommandTest: там страж появился после
 * инцидента с queue:prune-batches, которую тоже удалили осознанно.
 */
class ImportNewsCommandTest extends TestCase
{
    public function test_news_import_is_not_scheduled_but_still_available_by_hand(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertFalse(
            $commands->contains(fn (string $c): bool => str_contains($c, 'news:import')),
            'news:import вернулась в расписание: каждое утро она снова будет '
            .'перезапускать неразбираемые заглушки и жечь на них суточную квоту модели',
        );

        // Сама команда никуда не делась — иначе «снят с расписания»
        // незаметно превратилось бы в «удалён».
        $this->assertArrayHasKey('news:import', \Artisan::all());
    }
}

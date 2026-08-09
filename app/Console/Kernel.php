<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Telescope пишет в ту же БД и не чистится сам: без прунинга telescope_entries
        // разрослась до сотен тысяч строк с cookie, CSRF-токенами и биндингами запросов.
        $schedule->command('telescope:prune --hours=48')
            ->daily()
            ->onOneServer()
            ->withoutOverlapping();

        // failed_jobs копится с 2025 года и никем не разбирается.
        $schedule->command('queue:prune-failed --hours=336')
            ->weekly()
            ->onOneServer();

        $schedule->command('queue:prune-batches --hours=336')
            ->weekly()
            ->onOneServer();

        // Просроченные токены сброса пароля не удаляются сами: строки в
        // password_reset_tokens лежали вечно и уезжали в каждый бэкап.
        $schedule->command('auth:clear-resets')
            ->daily()
            ->onOneServer();

        // Бэкапы настроены в config/backup.php, но до сих пор не запускались ничем,
        // кроме ручной задачи Envoy. Только прод: локально это лишний мусор в storage/app.
        $schedule->command('backup:clean')
            ->daily()->at('01:00')
            ->environments(['production'])
            ->onOneServer();

        $schedule->command('backup:run')
            ->daily()->at('01:30')
            ->environments(['production'])
            ->onOneServer()
            ->withoutOverlapping();

        // Проверяет, что свежий бэкап реально существует и укладывается в лимиты
        // config/backup.php: без этого молчаливо сломавшийся backup:run никто не заметит.
        $schedule->command('backup:monitor')
            ->daily()->at('06:00')
            ->environments(['production'])
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

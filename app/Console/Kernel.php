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

        // queue:prune-batches здесь не стоит намеренно. Батчей в проекте нет
        // (ни одного Bus::batch), таблицы job_batches тоже — и команда падала
        // с «Base table or view not found» каждое воскресенье, когда наступал
        // её weekly-слот (на проде отметилась 16.08.2026). Остальное
        // расписание при этом отрабатывало: schedule:run ловит исключение
        // каждого события отдельно. Заводить таблицу ради чистки того, чего не
        // существует, незачем — правильный порядок обратный: сначала батчи,
        // потом их прунинг.

        // Записи роботов в post_views: их не читает ни одна выборка (аналитика
        // и счётчик под статьёй отсекают их скоупом), а держим мы их только
        // ради возможности пересмотреть вердикт детектора. Через квартал такой
        // разбор уже неинтересен. Живые просмотры команда не трогает.
        $schedule->command('post-views:prune')
            ->weekly()
            ->onOneServer();

        // Журнал поиска: аналитика дальше квартала не смотрит, а текст запроса
        // чувствительнее адреса страницы — держать его вечно значит копить
        // ответственность, которая ежедневно уезжает в бэкап.
        $schedule->command('search-queries:prune')
            ->weekly()
            ->onOneServer();

        // Журнал вызовов модели: за квартал аналитики его никто не читает, а
        // строки продолжают уезжать в ежедневный архив.
        $schedule->command('llm-calls:prune')
            ->weekly()
            ->onOneServer();

        // Новости из секции дайджеста. Дешёвая операция: сам дайджест уже
        // заведён как релиз, скачивается только он, а перевод идёт лишь для
        // новых элементов — повторные отсеиваются по url ещё до перевода.
        $schedule->command('news:import')
            ->dailyAt('07:00')
            ->onOneServer()
            ->withoutOverlapping();

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

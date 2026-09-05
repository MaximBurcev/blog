<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Виджет «Здоровье системы» на главной странице панели управления.
 *
 * Отображает состояние 6 критических компонентов:
 * 1. Место на диске
 * 2. База данных MySQL (размер и доступность)
 * 3. Очередь задач и статус воркеров
 * 4. Поисковый движок Meilisearch
 * 5. Последняя резервная копия
 * 6. WebSocket-сервер Reverb
 *
 * Опрашивается каждые 30 секунд. Рендерится сразу (isLazy = false),
 * чтобы не зависать скелетоном.
 */
class SystemHealthOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected ?string $heading = 'Здоровье системы';

    protected ?string $description = 'Состояние инфраструктуры, сервисов и очередей';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        return [
            $this->diskStat(),
            $this->databaseStat(),
            $this->queueStat(),
            $this->meilisearchStat(),
            $this->backupStat(),
            $this->reverbStat(),
        ];
    }

    private function diskStat(): Stat
    {
        try {
            $basePath = base_path();
            $free = @disk_free_space($basePath);
            $total = @disk_total_space($basePath);

            if ($free === false || $total === false || $total <= 0) {
                return Stat::make('Диск', 'недоступен')
                    ->description('Не удалось определить размер диска')
                    ->icon('heroicon-m-server-stack')
                    ->color('gray');
            }

            $freeGb = round($free / 1024 / 1024 / 1024, 1);
            $totalGb = round($total / 1024 / 1024 / 1024, 1);
            $freePercent = round(($free / $total) * 100);

            $color = 'success';
            if ($freePercent < 10 || $freeGb < 2) {
                $color = 'danger';
            } elseif ($freePercent < 20 || $freeGb < 5) {
                $color = 'warning';
            }

            return Stat::make('Диск', "{$freeGb} ГБ свободно")
                ->description("Из {$totalGb} ГБ ({$freePercent}% доступно)")
                ->icon('heroicon-m-server-stack')
                ->color($color);
        } catch (Throwable) {
            return Stat::make('Диск', 'ошибка')
                ->description('Сбой при проверке хранилища')
                ->icon('heroicon-m-server-stack')
                ->color('danger');
        }
    }

    private function databaseStat(): Stat
    {
        try {
            DB::connection()->getPdo();
            $dbName = (string) DB::connection()->getDatabaseName();

            $row = DB::selectOne('
                SELECT SUM(data_length + index_length) AS size_bytes,
                       COUNT(*) AS tables_count
                FROM information_schema.tables
                WHERE table_schema = ?
            ', [$dbName]);

            $sizeBytes = (int) ($row->size_bytes ?? 0);
            $tablesCount = (int) ($row->tables_count ?? 0);
            $sizeMb = round($sizeBytes / 1024 / 1024, 1);

            return Stat::make('База данных', "{$sizeMb} МБ")
                ->description("«{$dbName}» • {$tablesCount} табл.")
                ->icon('heroicon-m-circle-stack')
                ->color('success');
        } catch (Throwable) {
            return Stat::make('База данных', 'недоступна')
                ->description('Ошибка подключения к MySQL')
                ->icon('heroicon-m-circle-stack')
                ->color('danger');
        }
    }

    private function queueStat(): Stat
    {
        try {
            $connection = config('queue.connections.database.connection');
            $table = config('queue.connections.database.table', 'jobs');

            $row = DB::connection($connection)->table($table)
                ->selectRaw('
                    SUM(reserved_at IS NOT NULL) as running,
                    SUM(reserved_at IS NULL) as pending,
                    MIN(CASE WHEN reserved_at IS NULL THEN available_at END) as oldest_pending
                ')
                ->first();

            $running = (int) ($row->running ?? 0);
            $pending = (int) ($row->pending ?? 0);
            $oldestPending = isset($row->oldest_pending)
                ? Carbon::createFromTimestamp($row->oldest_pending)
                : null;

            $failed = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count();

            $workers = 0;
            if (function_exists('shell_exec')) {
                $output = @shell_exec('pgrep -f "artisan queue:work" | wc -l');
                $workers = (int) trim((string) $output);
            }

            // Оценка состояния: задачи лежат дольше 2 минут и ни одна не взята
            $isStale = $oldestPending !== null && $running === 0 && $oldestPending->diffInSeconds(now()) > 120;

            if ($isStale) {
                return Stat::make('Очередь задач', 'зависла')
                    ->description('Задачи не берутся в работу > 2 мин.')
                    ->icon('heroicon-m-cpu-chip')
                    ->color('danger');
            }

            if ($running > 0) {
                $value = "В работе: {$running}";
            } elseif ($workers > 0) {
                $value = "Воркеры: {$workers}";
            } else {
                $value = $pending > 0 ? 'Воркер остановлен' : 'Очередь пуста';
            }

            $descParts = [];
            if ($workers > 0) {
                $descParts[] = "активно: {$workers}";
            }
            $descParts[] = "в очереди: {$pending}";
            if ($failed > 0) {
                $descParts[] = "ошибок: {$failed}";
            }
            $description = implode(' • ', $descParts);

            $color = 'success';
            if ($workers === 0 && $pending > 0) {
                $color = 'danger';
            } elseif ($failed > 0) {
                $color = 'warning';
            }

            return Stat::make('Очередь задач', $value)
                ->description($description)
                ->icon('heroicon-m-cpu-chip')
                ->color($color);
        } catch (Throwable) {
            return Stat::make('Очередь задач', 'недоступна')
                ->description('Ошибка чтения таблицы задач')
                ->icon('heroicon-m-cpu-chip')
                ->color('danger');
        }
    }

    private function meilisearchStat(): Stat
    {
        $driver = config('scout.driver');

        if ($driver !== 'meilisearch') {
            return Stat::make('Поиск (Meili)', 'отключен')
                ->description("Scout-драйвер: {$driver}")
                ->icon('heroicon-m-magnifying-glass')
                ->color('gray');
        }

        $host = rtrim((string) config('scout.meilisearch.host', 'http://localhost:7700'), '/');
        $key = config('scout.meilisearch.key');
        $hostLabel = (string) (parse_url($host, PHP_URL_HOST) ?: 'localhost');
        if ($port = parse_url($host, PHP_URL_PORT)) {
            $hostLabel .= ":{$port}";
        }

        try {
            $response = Http::timeout(1.5)
                ->when(filled($key), fn ($req) => $req->withToken($key))
                ->get($host.'/health');

            $isAvailable = $response->successful() && (
                $response->json('status') === 'available' || $response->json('status') === 'ok'
            );

            if ($isAvailable) {
                return Stat::make('Поиск (Meili)', 'доступен')
                    ->description("Хост: {$hostLabel}")
                    ->icon('heroicon-m-magnifying-glass')
                    ->color('success');
            }

            return Stat::make('Поиск (Meili)', 'ошибка')
                ->description("Ответ {$response->status()} от {$hostLabel}")
                ->icon('heroicon-m-magnifying-glass')
                ->color('danger');
        } catch (Throwable) {
            return Stat::make('Поиск (Meili)', 'недоступен')
                ->description("Не отвечает: {$hostLabel}")
                ->icon('heroicon-m-magnifying-glass')
                ->color('danger');
        }
    }

    private function backupStat(): Stat
    {
        try {
            $backupName = config('backup.backup.name', 'blog');
            $disks = (array) config('backup.backup.destination.disks', ['local']);

            $latestTime = null;
            $latestSize = 0;
            $diskFound = null;

            foreach ($disks as $disk) {
                try {
                    $files = Storage::disk($disk)->files($backupName);
                    foreach ($files as $file) {
                        if (str_ends_with($file, '.zip')) {
                            $time = Storage::disk($disk)->lastModified($file);
                            if ($latestTime === null || $time > $latestTime) {
                                $latestTime = $time;
                                $latestSize = Storage::disk($disk)->size($file);
                                $diskFound = $disk;
                            }
                        }
                    }
                } catch (Throwable) {
                    // Переходим к следующему диску
                }
            }

            if ($latestTime === null) {
                return Stat::make('Резервная копия', 'нет бэкапов')
                    ->description('Архивы не найдены в хранилище')
                    ->icon('heroicon-m-archive-box')
                    ->color('danger');
            }

            $date = Carbon::createFromTimestamp($latestTime);
            $ageHours = $date->diffInHours(now());
            $sizeMb = round($latestSize / 1024 / 1024, 2);

            $color = 'success';
            if ($ageHours > 48) {
                $color = 'danger';
            } elseif ($ageHours > 26) {
                $color = 'warning';
            }

            return Stat::make('Резервная копия', $date->diffForHumans())
                ->description("Диск: {$diskFound} • {$sizeMb} МБ")
                ->icon('heroicon-m-archive-box')
                ->color($color);
        } catch (Throwable) {
            return Stat::make('Резервная копия', 'ошибка')
                ->description('Не удалось проверить архивы')
                ->icon('heroicon-m-archive-box')
                ->color('danger');
        }
    }

    private function reverbStat(): Stat
    {
        try {
            $broadcastDriver = config('broadcasting.default');

            if ($broadcastDriver !== 'reverb') {
                return Stat::make('WebSockets', 'отключен')
                    ->description("Драйвер: {$broadcastDriver}")
                    ->icon('heroicon-m-bolt')
                    ->color('gray');
            }

            $host = (string) config('reverb.servers.reverb.host', '127.0.0.1');
            if ($host === '0.0.0.0' || $host === '') {
                $host = '127.0.0.1';
            }
            $port = (int) config('reverb.servers.reverb.port', 8080);

            $fp = @fsockopen($host, $port, $errno, $errstr, 1.0);

            if ($fp) {
                fclose($fp);

                return Stat::make('WebSockets', 'работает')
                    ->description("Reverb на порту {$port}")
                    ->icon('heroicon-m-bolt')
                    ->color('success');
            }

            return Stat::make('WebSockets', 'не запущен')
                ->description("Порт {$port} не отвечает")
                ->icon('heroicon-m-bolt')
                ->color('danger');
        } catch (Throwable) {
            return Stat::make('WebSockets', 'ошибка')
                ->description('Сбой при проверке сокета')
                ->icon('heroicon-m-bolt')
                ->color('danger');
        }
    }
}

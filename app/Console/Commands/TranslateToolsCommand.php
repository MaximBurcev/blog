<?php

namespace App\Console\Commands;

use App\Service\ToolImportService;
use Illuminate\Console\Command;

class TranslateToolsCommand extends Command
{
    protected $signature = 'tools:translate
        {--limit=50 : Сколько описаний взять за прогон}';

    protected $description = 'Переводит описания инструментов, оставшиеся английскими';

    public function handle(ToolImportService $service): int
    {
        $stats = $service->translatePending((int) $this->option('limit'));

        if ($stats['found'] === 0) {
            $this->info('Непереведённых описаний нет.');

            return self::SUCCESS;
        }

        $this->info("Взято {$stats['found']}, переведено {$stats['translated']}");

        if ($stats['translated'] < $stats['found']) {
            $this->warn(
                'Остались английскими: '.($stats['found'] - $stats['translated']).
                '. Движки недоступны (квота модели, отказ скрейпера) — повторите позже.'
            );
        }

        return self::SUCCESS;
    }
}

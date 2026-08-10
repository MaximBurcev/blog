<?php

namespace App\Console\Commands;

use App\Models\Release;
use App\Service\NewsImportService;
use Illuminate\Console\Command;

class ImportNewsCommand extends Command
{
    protected $signature = 'news:import
        {url? : URL дайджеста; без него берутся все заведённые релизы}';

    protected $description = 'Импортирует новости из секции «News and Announcements» дайджеста';

    public function handle(NewsImportService $service): int
    {
        $urls = $this->argument('url')
            ? [$this->argument('url')]
            : Release::query()->pluck('url')->all();

        if ($urls === []) {
            $this->warn('Нет ни одного релиза — укажите URL аргументом.');

            return self::FAILURE;
        }

        $total = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($urls as $url) {
            $this->line("Дайджест: {$url}");

            try {
                $stats = $service->importFromDigest($url);
            } catch (\Throwable $e) {
                $this->error('  '.mb_substr($e->getMessage(), 0, 140));

                continue;
            }

            $this->info("  добавлено {$stats['imported']}, пропущено {$stats['skipped']}");

            foreach ($stats as $k => $v) {
                $total[$k] += $v;
            }
        }

        $this->newLine();
        $this->info("Итого: добавлено {$total['imported']}, пропущено {$total['skipped']}");

        return self::SUCCESS;
    }
}

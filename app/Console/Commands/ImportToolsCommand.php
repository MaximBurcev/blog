<?php

namespace App\Console\Commands;

use App\Models\Release;
use App\Service\ToolImportService;
use Illuminate\Console\Command;

class ImportToolsCommand extends Command
{
    protected $signature = 'tools:import
        {url? : URL дайджеста; без него берутся все заведённые релизы}';

    protected $description = 'Импортирует утилиты и библиотеки из секции «Interesting Projects, Tools and Libraries»';

    public function handle(ToolImportService $service): int
    {
        $urls = $this->argument('url')
            ? [$this->argument('url')]
            : Release::query()->pluck('url')->all();

        if ($urls === []) {
            $this->warn('Нет ни одного релиза — укажите URL аргументом.');

            return self::FAILURE;
        }

        if (! $this->argument('url')) {
            $this->warn(
                'Будут обойдены все релизы ('.count($urls).'). Каждый — это скачивание страницы, '.
                'а у дайджестов без этой секции результат пустой. Обычно нужен конкретный URL.'
            );
        }

        $total = ['found' => 0, 'created' => 0, 'skipped' => 0, 'rejected' => 0, 'translated' => 0];

        foreach ($urls as $url) {
            $this->line("Дайджест: {$url}");

            try {
                $stats = $service->importFromDigest($url);
            } catch (\Throwable $e) {
                $this->error('  '.mb_substr($e->getMessage(), 0, 140));

                continue;
            }

            $this->info("  найдено {$stats['found']}, добавлено {$stats['created']}, пропущено {$stats['skipped']}");

            foreach ($stats as $k => $v) {
                $total[$k] += $v;
            }
        }

        $this->newLine();
        $this->info("Итого: добавлено {$total['created']}, пропущено {$total['skipped']}");

        if ($total['created'] > $total['translated']) {
            $this->warn(
                'Без перевода осталось '.($total['created'] - $total['translated']).
                ' — показываются английские описания из дайджеста. Обычная причина: исчерпана суточная квота модели.'
            );
        }

        if ($total['rejected'] > 0) {
            $this->warn("Отброшено ссылок: {$total['rejected']} — не помещаются в колонку url, см. лог.");
        }

        return self::SUCCESS;
    }
}

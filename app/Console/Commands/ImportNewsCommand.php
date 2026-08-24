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

        $total = ['dispatched' => 0, 'skipped' => 0, 'exhausted' => 0];

        foreach ($urls as $url) {
            $this->line("Дайджест: {$url}");

            try {
                $stats = $service->importFromDigest($url);
            } catch (\Throwable $e) {
                $this->error('  '.mb_substr($e->getMessage(), 0, 140));

                continue;
            }

            $this->info("  поставлено задач {$stats['dispatched']}, пропущено {$stats['skipped']}");

            foreach ($stats as $k => $v) {
                $total[$k] += $v;
            }
        }

        $this->newLine();
        $this->info("Итого: поставлено задач {$total['dispatched']}, пропущено {$total['skipped']}");

        // Отброшенные по исчерпанным попыткам показываем отдельной строкой, а
        // не молча в «пропущено»: это не «уже разобрано», а «сдались». Тихо
        // растущее число здесь означает, что дайджест носит ссылки, которые
        // мы разобрать не умеем, — повод посмотреть на них, а не привыкнуть.
        if ($total['exhausted'] > 0) {
            $this->warn(
                "Не будем больше пробовать: {$total['exhausted']} — попытки исчерпаны ".
                '(лимит releases.news_retry_limit). Они видны в админке как посты с ошибкой разбора.'
            );
        }

        $this->line('Разбор идёт в очереди — следите за разделом «Посты» в админке.');

        return self::SUCCESS;
    }
}

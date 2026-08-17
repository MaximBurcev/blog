<?php

namespace App\Console\Commands;

use App\Service\DiagramTranslatorService;
use Illuminate\Console\Command;

class TranslateImageCommand extends Command
{
    protected $signature = 'image:translate
        {path : Путь к картинке (абсолютный, либо относительно storage/app/public)}
        {--heading-only : Режим обложки — перевести только самый крупный блок текста, подпись и логотипы не трогать}';

    protected $description = 'Распознаёт и переводит текст на картинке (диаграммы, скриншоты) через OCR';

    public function handle(DiagramTranslatorService $service): int
    {
        $path = $this->argument('path');
        if (! str_starts_with($path, '/')) {
            $path = storage_path('app/public/'.ltrim($path, '/'));
        }

        if (! file_exists($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $headingOnly = (bool) $this->option('heading-only');

        $this->info($headingOnly
            ? "Распознаю и перевожу заголовок: {$path}"
            : "Распознаю и перевожу текст: {$path}");

        $translated = $service->translate($path, $headingOnly);

        if ($translated) {
            $this->info('Готово — картинка перезаписана переведённой версией.');
        } else {
            // Три исхода — «текста нет», «переводить нечего» и «OCR не
            // отработал» — здесь неразличимы: translate() отдаёт bool. В логе
            // они разведены, поэтому отправляем туда, а не выдаём отказ
            // инструмента за отсутствие текста, как было до 17.08.2026.
            $this->warn('Картинка не изменена: текста нет, переводить нечего либо OCR не отработал — подробности в логе.');
        }

        return self::SUCCESS;
    }
}

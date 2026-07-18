<?php

namespace App\Console\Commands;

use App\Service\DiagramTranslatorService;
use Illuminate\Console\Command;

class TranslateImageCommand extends Command
{
    protected $signature = 'image:translate
        {path : Путь к картинке (абсолютный, либо относительно storage/app/public)}';

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

        $this->info("Распознаю и перевожу текст: {$path}");

        $translated = $service->translate($path);

        if ($translated) {
            $this->info('Готово — картинка перезаписана переведённой версией.');
        } else {
            $this->warn('Текст не найден или переводить нечего — картинка не изменена.');
        }

        return self::SUCCESS;
    }
}

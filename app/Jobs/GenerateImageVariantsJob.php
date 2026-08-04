<?php

namespace App\Jobs;

use App\Service\ImageVariantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Уменьшенные копии превью.
 *
 * Раньше генерация висела прямо в хуке Post::saved: до шести декодирований и
 * кодирований WebP выполнялись синхронно внутри HTTP-запроса админки и —
 * что хуже — внутри открытой транзакции PostService::store(), которая держала
 * строки заблокированными всё время работы GD.
 */
class GenerateImageVariantsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(private readonly string $path) {}

    /**
     * Дедуп по пути: preview_image и main_image у поста обычно один и тот же
     * файл, и без этого на каждое сохранение ставились две одинаковые задачи.
     */
    public function uniqueId(): string
    {
        return $this->path;
    }

    public function handle(ImageVariantService $variants): void
    {
        $created = $variants->generate($this->path);

        if ($created !== []) {
            Log::info('Созданы варианты изображения', [
                'path' => $this->path,
                'variants' => count($created),
            ]);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Service\ImageVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill уменьшенных копий превью для уже существующих постов.
 *
 * Новым постам варианты делает Post::booted(), а этой командой добираем
 * накопленное. Идемпотентна: готовые варианты пропускаются, так что
 * повторный прогон безопасен.
 */
class GenerateImageVariantsCommand extends Command
{
    protected $signature = 'images:variants
        {--dry-run : Показать, сколько будет создано, без записи}';

    protected $description = 'Создаёт уменьшенные копии превью постов для srcset';

    public function handle(ImageVariantService $variants): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');

        $paths = Post::withTrashed()
            ->whereNotNull('preview_image')
            ->distinct()
            ->pluck('preview_image')
            ->all();

        $created = 0;
        $skipped = 0;
        $bytes = 0;
        $bar = $this->output->createProgressBar(count($paths));

        foreach ($paths as $path) {
            $bar->advance();

            if (! $disk->exists($path)) {
                continue;
            }

            if ($dryRun) {
                // Считаем недостающие, ничего не записывая.
                foreach (ImageVariantService::WIDTHS as $width) {
                    if (! $disk->exists($variants->variantPath($path, $width))) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                }

                continue;
            }

            $new = $variants->generate($path);
            $created += count($new);

            foreach ($new as $variantPath) {
                $bytes += $disk->size($variantPath);
            }
        }

        $bar->finish();
        $this->newLine();

        $this->info(sprintf(
            '%sПревью обработано: %d, вариантов создано: %d%s',
            $dryRun ? '[dry-run] ' : '',
            count($paths),
            $created,
            $dryRun ? ", уже готово: {$skipped}" : sprintf(' (%.1f МБ)', $bytes / 1048576),
        ));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Service\ImageVariantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        {--dry-run : Показать, сколько будет создано, без записи}
        {--force : Пересоздать варианты заново — нужно после смены набора ширин или качества}';

    protected $description = 'Создаёт уменьшенные копии превью постов для srcset';

    public function handle(ImageVariantService $variants): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $disk = Storage::disk('public');

        $paths = Post::withTrashed()
            ->whereNotNull('preview_image')
            ->distinct()
            ->pluck('preview_image')
            ->all();

        // Заглушка стоит в карточках постов без обложки, ей варианты нужны
        // на тех же основаниях.
        $paths[] = (string) Str::after(config('seo.default_image'), 'storage/');

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
                    if ($force || ! $disk->exists($variants->variantPath($path, $width))) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                }

                continue;
            }

            if ($force) {
                // generate() пропускает то, что уже лежит на диске, поэтому
                // при смене ширин или качества старое надо убрать руками.
                foreach (ImageVariantService::WIDTHS as $width) {
                    $disk->delete($variants->variantPath($path, $width));
                }
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

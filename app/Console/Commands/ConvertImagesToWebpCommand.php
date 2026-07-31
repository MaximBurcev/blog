<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Service\WebpConverterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Перегоняет уже скачанные картинки в WebP и переписывает ссылки на них.
 *
 * Новые картинки конвертируются на лету (ContentImageService::storeImage),
 * а этот backfill приводит к тому же виду накопленное: на момент внедрения
 * в хранилище лежало 267 PNG на 108 МБ (SEO-аудит 2026-07-30).
 *
 * Отдельный случай — файлы, которые уже WebP, но лежат с расширением .png
 * или .jpg: источники отдают WebP по «png»-адресам, а имя файла бралось из
 * URL. Перекодировать их незачем (потеря качества на ровном месте), но
 * переименовать стоит, иначе Apache отдаёт их с неверным Content-Type.
 *
 * Оригиналы не удаляются: пути к ним могут остаться во внешних кэшах и в
 * content_orig. Чистить хранилище — отдельная осознанная операция.
 *
 * Команда идемпотентна, её можно гонять повторно: файл со сконвертированным
 * двойником пропускается, а готовый WebP не пережимается по второму кругу
 * (quality 82 поверх quality 82 — это только потеря качества).
 */
class ConvertImagesToWebpCommand extends Command
{
    protected $signature = 'images:to-webp
        {--dry-run : Показать, что будет сделано, без записи}';

    protected $description = 'Конвертирует картинки постов в WebP и обновляет ссылки в БД';

    private const CONVERTIBLE = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * Порог, за которым уже лежащий в хранилище WebP считается сырым и его
     * стоит пережать. Собственный результат конвертера в такой размер не
     * попадает: после первого прогона медиана вышла 31 КБ, p90 — 99 КБ,
     * потолок — 254 КБ. А исходники от источников бывают по полмегабайта.
     */
    private const RAW_WEBP_BYTES = 300 * 1024;

    public function handle(WebpConverterService $converter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');

        /** @var array<string, string> старый путь => новый путь */
        $renames = [];
        $bytesBefore = 0;
        $bytesAfter = 0;
        $converted = 0;
        $renamedOnly = 0;
        $skipped = 0;

        foreach ($disk->allFiles('images') as $path) {
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::CONVERTIBLE, true)) {
                continue;
            }

            $newPath = preg_replace('/\.[^.]+$/', '.webp', $path);

            // Двойник на месте — этот файл уже прогоняли, он остался лежать
            // оригиналом только потому, что команда их не удаляет.
            if ($path !== $newPath && $disk->exists($newPath)) {
                $skipped++;

                continue;
            }

            $binary = $disk->get($path);
            $isWebpAlready = (@getimagesizefromstring($binary)[2] ?? null) === IMAGETYPE_WEBP;

            // Готовый WebP разумного веса — это результат прошлого прогона
            // или конвертации на лету, трогать его нечего.
            if ($isWebpAlready && $path === $newPath && strlen($binary) <= self::RAW_WEBP_BYTES) {
                $skipped++;

                continue;
            }

            $webp = $converter->convert($binary);

            if ($webp === null) {
                // Пережимать нечего (кадр уже оптимален), но если это WebP
                // под расширением .png/.jpg — переименовываем, иначе Apache
                // отдаёт его с неверным Content-Type.
                if ($isWebpAlready && $path !== $newPath) {
                    $renames[$path] = $newPath;
                    $renamedOnly++;

                    if (! $dryRun) {
                        $disk->put($newPath, $binary);
                    }
                }

                continue;
            }

            $bytesBefore += strlen($binary);
            $bytesAfter += strlen($webp);
            $converted++;

            if ($path !== $newPath) {
                $renames[$path] = $newPath;
            }

            if (! $dryRun) {
                // Для уже .webp путь не меняется — перезаписываем на месте.
                $disk->put($newPath, $webp);
            }
        }

        $this->info(sprintf(
            '%sКонвертировано: %d (%.1f МБ → %.1f МБ), переименовано без перекодирования: %d, пропущено готовых: %d',
            $dryRun ? '[dry-run] ' : '',
            $converted,
            $bytesBefore / 1048576,
            $bytesAfter / 1048576,
            $renamedOnly,
            $skipped,
        ));

        if ($renames !== []) {
            $this->updatePosts($renames, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * Переписывает ссылки: preview_image/main_image хранят относительный
     * путь, а в теле статьи лежит полный URL — заменяем и то, и другое.
     */
    private function updatePosts(array $renames, bool $dryRun): void
    {
        $touched = 0;

        Post::withTrashed()->chunkById(100, function ($posts) use ($renames, $dryRun, &$touched) {
            foreach ($posts as $post) {
                $changed = false;

                foreach (['preview_image', 'main_image'] as $field) {
                    if ($post->$field !== null && isset($renames[$post->$field])) {
                        $post->$field = $renames[$post->$field];
                        $changed = true;
                    }
                }

                foreach (['content', 'content_orig'] as $field) {
                    $value = $post->getRawOriginal($field);
                    if (blank($value)) {
                        continue;
                    }

                    $updated = strtr($value, $renames);
                    if ($updated !== $value) {
                        $post->$field = $updated;
                        $changed = true;
                    }
                }

                if (! $changed) {
                    continue;
                }

                $touched++;

                if (! $dryRun) {
                    // Технический backfill, а не авторская правка: не двигаем
                    // updated_at и не поднимаем события/реиндексацию.
                    $post->timestamps = false;
                    $post->saveQuietly();
                }
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."Постов с обновлёнными ссылками: {$touched}");
    }
}

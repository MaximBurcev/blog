<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;

/**
 * HtmlSanitizerService применяется только через мутатор Post::setContentAttribute(),
 * то есть при INSERT/UPDATE. Посты, сохранённые до внедрения санитайзинга,
 * остались с необработанным скрейпленным HTML в content и продолжают
 * рендериться через {!! !!} без повторной обработки (stored XSS).
 * Эта команда прогоняет существующие записи через тот же мутатор.
 */
class ResanitizePostsCommand extends Command
{
    protected $signature = 'posts:resanitize {--dry-run : Показать, сколько постов будет изменено, без записи}';

    protected $description = 'Пересанитайзить content всех постов через HtmlSanitizerService (backfill для постов, созданных до внедрения санитайзинга)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $total = 0;

        Post::withTrashed()->chunkById(100, function ($posts) use (&$changed, &$total, $dryRun) {
            foreach ($posts as $post) {
                $total++;
                $original = $post->getRawOriginal('content');

                $post->content = $original;

                if ($post->isDirty('content')) {
                    $changed++;
                    $this->line("Изменён контент поста #{$post->id} ({$post->code})");

                    if (! $dryRun) {
                        $post->save();
                    }
                }
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."Проверено постов: {$total}, изменено: {$changed}");

        return self::SUCCESS;
    }
}

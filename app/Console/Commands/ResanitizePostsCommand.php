<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;

/**
 * HtmlSanitizerService применяется только через мутаторы Post, то есть при
 * INSERT/UPDATE. Посты, сохранённые до внедрения санитайзинга, остались с
 * необработанным скрейпленным HTML и продолжают рендериться через {!! !!}
 * без повторной обработки (stored XSS). Эта команда прогоняет существующие
 * записи через те же мутаторы.
 *
 * content_orig добавлен сюда 14.08.2026 — вместе со страницей ?lang=en,
 * которая эту колонку выводит. Мутатор для неё появился на пять месяцев
 * позже самой колонки, так что записи с 22.02 по 27.07.2026 хранят сырой
 * HTML страницы-источника (на проде у двух уцелел <iframe>). На выводе он
 * санитайзится и так (Post::originalBody), но чистить сами данные всё равно
 * надо: следующий, кто выведет колонку, про это знать не обязан.
 */
class ResanitizePostsCommand extends Command
{
    /** Колонки со скрейпленным HTML — у каждой свой мутатор-санитайзер. */
    private const SANITIZED_COLUMNS = ['content', 'content_orig'];

    protected $signature = 'posts:resanitize {--dry-run : Показать, сколько постов будет изменено, без записи}';

    protected $description = 'Пересанитайзить content и content_orig всех постов через HtmlSanitizerService (backfill для постов, созданных до внедрения санитайзинга)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $total = 0;

        Post::withTrashed()->chunkById(100, function ($posts) use (&$changed, &$total, $dryRun) {
            foreach ($posts as $post) {
                $total++;

                foreach (self::SANITIZED_COLUMNS as $column) {
                    // Присваивание через мутатор: сам санитайзинг живёт там,
                    // и второй его копии здесь быть не должно.
                    $post->{$column} = $post->getRawOriginal($column);
                }

                if (! $post->isDirty(self::SANITIZED_COLUMNS)) {
                    continue;
                }

                $changed++;
                $this->line(sprintf(
                    'Изменён контент поста #%d (%s): %s',
                    $post->id,
                    $post->code,
                    implode(', ', array_keys($post->getDirty()))
                ));

                if (! $dryRun) {
                    // Backfill — это не авторская правка: не бампаем updated_at
                    // и не поднимаем события/Scout-реиндексацию (saveQuietly).
                    // В индекс уходит текст без разметки, а санитайзинг меняет
                    // только её, так что переиндексация ничего бы не изменила.
                    $post->timestamps = false;
                    $post->saveQuietly();
                }
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."Проверено постов: {$total}, изменено: {$changed}");

        return self::SUCCESS;
    }
}

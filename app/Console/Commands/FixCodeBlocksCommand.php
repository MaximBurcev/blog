<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;

/**
 * Возвращает в блок кода то, что из него вынес HTMLPurifier.
 *
 * Причину чинит HtmlSanitizerService::flattenCodeBlocks(), но уже сохранённым
 * постам это не поможет: исходная разметка не сохранилась ни в content, ни в
 * content_orig — обе колонки санитайзятся при записи. Зато сам код не потерян,
 * он просто лежит рядом с пустым блоком:
 *
 *   <pre><code></code></pre>
 *   <div><code><span>&lt;?php</span></code></div><code></code>
 *   <div><code><span>namespace App;</span></code></div><code></code>
 *
 * Форма выноса детерминированная (её задаёт модель контента HTML), поэтому
 * строки можно собрать обратно ровно в том порядке, в котором они шли. Это
 * дешевле и надёжнее повторного разбора: не тратит квоту модели, не меняет
 * перевод и не зависит от того, жив ли ещё источник.
 */
class FixCodeBlocksCommand extends Command
{
    protected $signature = 'posts:fix-code-blocks
        {--dry-run : Показать, что будет исправлено, без записи}
        {--id=* : Чинить только указанные посты}';

    protected $description = 'Возвращает код, вынесенный HTMLPurifier наружу, обратно в <pre><code>';

    /**
     * Пустой блок кода и следующая за ним россыпь вынесенных строк.
     *
     * Между строками попадаются пустые <code></code> — это остатки текстовых
     * узлов, разделявших блоки; их просто пропускаем.
     */
    private const BROKEN_BLOCK = '#<pre><code></code></pre>((?:\s*(?:<div><code>.*?</code></div>|<code></code>))+)#s';

    /**
     * Обе колонки со скрейпленным HTML, как в ResanitizePostsCommand: у
     * content_orig та же разметка подсветчика и тот же санитайзер, значит и
     * порча та же. Починив только content, мы оставили бы страницу ?lang=en
     * с пустой полосой и кодом россыпью.
     */
    private const REPAIRED_COLUMNS = ['content', 'content_orig'];

    public function handle(): int
    {
        $query = Post::query()->where(function ($q): void {
            foreach (self::REPAIRED_COLUMNS as $column) {
                $q->orWhere($column, 'like', '%<pre><code></code></pre>%');
            }
        });

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }

        $posts = $query->get();

        if ($posts->isEmpty()) {
            $this->info('Постов с вынесенным кодом нет.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        foreach ($posts as $post) {
            $blocks = 0;

            foreach (self::REPAIRED_COLUMNS as $column) {
                $value = (string) $post->{$column};

                if ($value === '') {
                    continue;
                }

                // Через модель, а не update(): у каждой колонки свой
                // мутатор-санитайзер, и в базу ляжет ровно то, что увидит
                // читатель.
                $post->{$column} = $this->repair($value, $blocks);
            }

            if ($blocks === 0) {
                $this->warn("  #{$post->id}: пустой блок есть, но собрать строки не удалось — нужен ручной разбор");

                continue;
            }

            $this->line("  #{$post->id}: восстановлено блоков — {$blocks} · ".mb_substr((string) $post->title, 0, 50));

            if (! $dryRun) {
                // Backfill — не авторская правка: не бампаем updated_at и не
                // поднимаем события (та же логика, что в ResanitizePostsCommand).
                // Бампнутый updated_at ушёл бы в sitemap и в dateModified
                // разметки, объявив полтора десятка статей изменёнными сегодня.
                $post->timestamps = false;
                $post->saveQuietly();
            }

            $fixed++;
        }

        $this->newLine();
        $this->info($dryRun
            ? "Будет исправлено постов: {$fixed} (запуск с --dry-run)"
            : "Исправлено постов: {$fixed}");

        return self::SUCCESS;
    }

    private function repair(string $content, int &$blocks): string
    {
        $result = preg_replace_callback(
            self::BROKEN_BLOCK,
            function (array $matches) use (&$blocks): string {
                $lines = [];

                if (preg_match_all('#<div><code>(.*?)</code></div>#s', $matches[1], $found) === false) {
                    return $matches[0];
                }

                foreach ($found[1] as $line) {
                    // Сущности не трогаем: код в них уже экранирован, и
                    // декодировать его значило бы вернуть в разметку живой тег.
                    $lines[] = rtrim(strip_tags($line));
                }

                if ($lines === []) {
                    return $matches[0];
                }

                $blocks++;

                return '<pre><code>'.rtrim(implode("\n", $lines))."</code></pre>\n";
            },
            $content
        );

        return $result ?? $content;
    }
}

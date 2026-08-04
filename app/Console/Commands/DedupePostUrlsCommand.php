<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Готовит posts к UNIQUE-индексу на url.
 *
 * url — единственный естественный ключ дедупликации скрейпленной статьи
 * (PostService::store() делает по нему updateOrCreate), но индекс на колонке
 * был обычный, а не уникальный. Поэтому две джобы, стартовавшие одновременно,
 * успевали обе не найти пост и обе его создать — в базе накопились дубли.
 *
 * Удаление постов — операция необратимая и не для миграции: без флага --force
 * команда только показывает план.
 */
class DedupePostUrlsCommand extends Command
{
    protected $signature = 'posts:dedupe-urls {--force : Выполнить слияние и удаление, а не только показать план}';

    protected $description = 'Схлопывает посты с одинаковым url, оставляя по одному на адрес';

    public function handle(): int
    {
        $urls = Post::withTrashed()
            ->whereNotNull('url')
            ->where('url', '<>', '')
            ->select('url')
            ->groupBy('url')
            ->havingRaw('count(*) > 1')
            ->pluck('url');

        if ($urls->isEmpty()) {
            $this->info('Дублей по url нет — posts готов к UNIQUE-индексу.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $this->warn(sprintf('Групп дублей: %d%s', $urls->count(), $force ? '' : ' (режим показа плана, ничего не меняется)'));

        $deleted = 0;

        foreach ($urls as $url) {
            $rows = $this->sortByPriority(
                Post::withTrashed()->where('url', $url)->get()
            );

            $keeper = $rows->shift();

            $this->line('');
            $this->line('<comment>'.$url.'</comment>');
            $this->line(sprintf(
                '  оставить  #%d (%s, content %d симв.%s)',
                $keeper->id,
                $keeper->published ? 'опубликован' : 'черновик',
                mb_strlen((string) $keeper->content),
                $keeper->trashed() ? ', в корзине' : ''
            ));

            foreach ($rows as $loser) {
                $this->line(sprintf(
                    '  удалить   #%d (%s, content %d симв.%s)',
                    $loser->id,
                    $loser->published ? 'опубликован' : 'черновик',
                    mb_strlen((string) $loser->content),
                    $loser->trashed() ? ', в корзине' : ''
                ));

                if ($force) {
                    $this->merge($keeper, $loser);
                    $deleted++;
                }
            }
        }

        $this->line('');

        if (! $force) {
            $this->warn('Ничего не изменено. Повторите с --force, если план верен.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Удалено дублей: %d. Теперь можно накатывать UNIQUE-индекс (php artisan migrate).', $deleted));

        return self::SUCCESS;
    }

    /**
     * Кого оставляем: живого важнее удалённого, опубликованного важнее
     * черновика (он уже в выдаче и на него ведут внешние ссылки), с телом
     * важнее пустого, а при прочих равных — более свежий разбор.
     *
     * Именно свежий, а не самый объёмный: длина тела — плохой признак
     * качества. В группе clean-architecture-in-laravel самая длинная копия
     * (118 КБ против 16 КБ) оказалась мусорной — с артефактом
     * «<?xml encoding="utf-8" ?>» в теле и двумя десятками лишних <img>
     * от старой версии парсера. Более поздний разбор сделан более поздним
     * парсером, и это устойчивее коррелирует с качеством.
     *
     * @param  Collection<int, Post>  $rows
     * @return Collection<int, Post>
     */
    private function sortByPriority(Collection $rows): Collection
    {
        return $rows->sortBy([
            fn (Post $a, Post $b) => ($a->trashed() ? 1 : 0) <=> ($b->trashed() ? 1 : 0),
            fn (Post $a, Post $b) => ($b->published ? 1 : 0) <=> ($a->published ? 1 : 0),
            fn (Post $a, Post $b) => (filled($b->content) ? 1 : 0) <=> (filled($a->content) ? 1 : 0),
            fn (Post $a, Post $b) => $b->id <=> $a->id,
        ])->values();
    }

    /**
     * Переносит на оставляемый пост теги дубля, которых у него ещё нет, и
     * физически удаляет дубль. Комментарии, лайки и просмотры не переносятся
     * намеренно: их у дублей не бывает (дубль создаётся джобой парсера и
     * публично недостижим), а тихое перемешивание пользовательских данных
     * между постами хуже явного отказа — если связи появятся, команда упадёт
     * на внешнем ключе, и это будет видно.
     */
    private function merge(Post $keeper, Post $loser): void
    {
        DB::transaction(function () use ($keeper, $loser) {
            $keeperTags = $keeper->tags()->pluck('tags.id')->all();
            $moving = $loser->tags()->pluck('tags.id')->diff($keeperTags)->all();

            if ($moving !== []) {
                $keeper->tags()->attach($moving);
            }

            $loser->tags()->detach();
            $loser->forceDelete();
        });
    }
}

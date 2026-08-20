<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Service\TranslateService;
use Illuminate\Console\Command;

class TranslatePostCommand extends Command
{
    protected $signature = 'post:translate
        {id : ID поста для перевода}
        {--dry-run : Показать результат, не сохраняя}
        {--force : Перевести даже без сохранённого оригинала}';

    protected $description = 'Переводит заголовок и контент поста на русский язык';

    public function handle(TranslateService $translateService): int
    {
        $post = Post::find($this->argument('id'));

        if (! $post) {
            $this->error("Пост с ID {$this->argument('id')} не найден.");

            return self::FAILURE;
        }

        /*
         * Без сохранённого оригинала переводить нечего: в content лежит уже
         * русский текст, и повторный прогон переведёт перевод. На обложках это
         * ровно так и вышло 17.08.2026 — английская модель прочитала кириллицу
         * как латиницу, «Топ-16 обязательных ресурсов» стало «Ton-16
         * pecypcosB», и файлы перезаписались без бэкапа.
         *
         * Здесь потеря такая же безвозвратная: content перезапишется, а
         * восстановить его будет неоткуда.
         */
        if (blank($post->content_orig) && ! $this->option('force')) {
            $this->error('У поста нет сохранённого оригинала (content_orig).');
            $this->line('Перевод перезаписал бы русский текст переводом самого себя.');
            $this->line('Если оригинал точно в content — повторите с --force.');

            return self::FAILURE;
        }

        // С --force оригинала нет, а перевод перезапишет content безвозвратно.
        // Раз уж мы верим, что в content лежит оригинал, — сохраним его, и
        // операция станет обратимой: повторный прогон возьмёт content_orig.
        if (blank($post->content_orig) && ! $this->option('dry-run')) {
            $post->update(['content_orig' => $post->content]);
            $this->comment('Оригинал сохранён в content_orig — перевод обратим.');
        }

        $this->info("Перевод поста: {$post->title}");

        $data = $translateService->translate([
            'title' => $post->title,
            'content' => $post->content_orig ?: $post->content,
            'selector' => '',
            'url' => '',
        ]);

        $engine = $data['translated_by'] ?? 'неизвестно';
        $incomplete = ! empty($data['translation_incomplete']);

        $this->newLine();
        $this->line("<info>Движок:</info> {$engine}".($incomplete ? ' <comment>(перевод неполный)</comment>' : ''));
        $this->line("<info>Заголовок:</info> {$data['title']}");
        $this->newLine();
        $this->line(mb_substr(strip_tags($data['content']), 0, 1000));
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->comment('Пробный прогон: ничего не сохранено.');

            return self::SUCCESS;
        }

        // code не трогаем: он уже является публичным адресом сохранённого
        // поста. Перевод заголовка от прогона к прогону гуляет, и пересчёт
        // кода уводил статью на новый URL, а старый начинал отдавать 404 —
        // ровно тот инвариант, который PostService защищает keepExistingCode().
        // Переименование остаётся ручной операцией через админку.
        $post->update([
            'title' => $data['title'],
            'content' => $data['content'],
            'translation_incomplete' => $incomplete,
            'translated_by' => $data['translated_by'] ?? null,
        ]);

        $this->info("Готово: {$post->title}");

        return self::SUCCESS;
    }
}

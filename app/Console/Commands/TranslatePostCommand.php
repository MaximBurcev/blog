<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Service\TranslateService;
use Illuminate\Console\Command;

class TranslatePostCommand extends Command
{
    protected $signature = 'post:translate
        {id : ID поста для перевода}';

    protected $description = 'Переводит заголовок и контент поста на русский язык';

    public function handle(TranslateService $translateService): int
    {
        $post = Post::find($this->argument('id'));

        if (! $post) {
            $this->error("Пост с ID {$this->argument('id')} не найден.");

            return self::FAILURE;
        }

        $this->info("Перевод поста: {$post->title}");

        $content = $post->content_orig ?: $post->content;

        $data = [
            'title' => $post->title,
            'content' => $content,
            'selector' => '',
            'url' => '',
        ];

        $data = $translateService->translate($data);

        // code не трогаем: он уже является публичным адресом сохранённого
        // поста. Перевод заголовка от прогона к прогону гуляет, и пересчёт
        // кода уводил статью на новый URL, а старый начинал отдавать 404 —
        // ровно тот инвариант, который PostService защищает keepExistingCode().
        // Переименование остаётся ручной операцией через админку.
        $post->update([
            'title' => $data['title'],
            'content' => $data['content'],
        ]);

        $this->info("Готово: {$post->title}");

        return self::SUCCESS;
    }
}

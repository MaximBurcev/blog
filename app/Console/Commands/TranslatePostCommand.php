<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Service\TranslateService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TranslatePostCommand extends Command
{
    protected $signature = 'post:translate
        {id : ID поста для перевода}';

    protected $description = 'Переводит заголовок и контент поста на русский язык';

    public function handle(): int
    {
        $post = Post::find($this->argument('id'));

        if (!$post) {
            $this->error("Пост с ID {$this->argument('id')} не найден.");
            return self::FAILURE;
        }

        $this->info("Перевод поста: {$post->title}");

        $content = $post->content_orig ?: $post->content;

        $data = [
            'title'    => $post->title,
            'content'  => $content,
            'selector' => '',
            'url'      => '',
        ];

        $translateService = new TranslateService($data);
        $data = $translateService->translate();

        $post->update([
            'title'   => $data['title'],
            'content' => $data['content'],
            'code'    => Str::slug($data['title'], '-', 'ru'),
        ]);

        $this->info("Готово: {$post->title}");

        return self::SUCCESS;
    }
}

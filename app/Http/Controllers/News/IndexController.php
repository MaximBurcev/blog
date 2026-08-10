<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Contracts\View\View;

/**
 * Лента новостей.
 *
 * Новость — тот же Post с флагом is_news: разбирается, переводится и
 * хранится ровно тем же пайплайном, что и статья, поэтому у неё есть и
 * полный текст, и своя страница (/posts/{code}). Отдельного адреса под
 * новость не заводим — это был бы второй URL для того же контента.
 */
class IndexController extends Controller
{
    private const PER_PAGE = 15;

    public function __invoke(): View
    {
        $posts = Post::published()->news()
            ->select(['id', 'title', 'code', 'preview_image', 'category_id', 'created_at', 'url'])
            ->latest('created_at')
            ->paginate(self::PER_PAGE);

        return view('news.index', [
            'posts' => $posts,
            'title' => 'Новости',
            'description' => 'Новости и анонсы мира PHP: релизы фреймворков, инструменты и события — в переводе на русский.',
        ]);
    }
}

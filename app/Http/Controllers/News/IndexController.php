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
        // is_news обязателен в select: на нём стоит Post::permalink() (у
        // новости адрес /news/{code}), а невыбранная колонка молча читается
        // как null — и новость получила бы адрес статьи.
        $posts = Post::published()->news()
            ->select(['id', 'title', 'code', 'preview_image', 'category_id', 'created_at', 'url', 'is_news'])
            ->latest('created_at')
            ->paginate(self::PER_PAGE);

        // Каноническая — текущая страница пагинации, как на главной и в
        // разделах: url()->current() отбросил бы ?page=N и склеил все
        // страницы ленты в первую.
        $canonical = $posts->currentPage() > 1
            ? $posts->url($posts->currentPage())
            : url()->current();
        // См. Category\ShowController: пустой список закрываем от индексации,
        // а не отдаём 404 — новости появятся при следующем импорте. isEmpty()
        // ловит и ?page=N за пределами пагинации.
        $robots = $posts->isEmpty() ? 'noindex, follow' : null;

        return view('news.index', [
            'posts' => $posts,
            'title' => 'Новости',
            'description' => 'Новости и анонсы мира PHP: релизы фреймворков, инструменты и события — в переводе на русский.',
            'canonical' => $canonical,
            'robots' => $robots,
        ]);
    }
}

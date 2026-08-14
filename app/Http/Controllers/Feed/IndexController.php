<?php

namespace App\Http\Controllers\Feed;

use App\Http\Controllers\Controller;
use App\Models\Post;

class IndexController extends Controller
{
    public function __invoke()
    {
        // without('category') — не используется в feed.index; select() —
        // только колонки, которые реально нужны шаблону (title/code/content
        // для excerpt()/created_at), а не весь Post. is_news обязателен: на
        // нём стоит Post::permalink(), а невыбранная колонка молча читается
        // как null — то есть каждая новость получила бы адрес статьи и 301
        // прямо в <guid isPermaLink="true">, который читалки считают
        // идентификатором записи.
        $posts = Post::published()->without('category')
            ->select('id', 'title', 'code', 'content', 'created_at', 'is_news')
            ->orderBy('created_at', 'desc')
            ->take(30)
            ->get();

        return response()
            ->view('feed.index', compact('posts'))
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}

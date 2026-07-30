<?php

namespace App\Http\Controllers\Main;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $posts = [];
        if ($request->get('q')) {
            // Фильтр по published — на случай, если пост сняли с публикации
            // уже после индексации: показывать такой в выдаче нельзя, ссылка
            // ведёт на 404 (Post\ShowController отдаёт только опубликованные).
            $posts = Post::search($request->get('q'))->where('published', true)->get();
        }
        $title = 'Поиск';
        // Страницы результатов индексировать не нужно: ?q=… порождает
        // бесконечное множество почти пустых дублей. follow — чтобы краулер
        // всё же прошёл по ссылкам на сами посты.
        $robots = 'noindex, follow';

        return view('main.search', compact('posts', 'title', 'robots'));
    }
}

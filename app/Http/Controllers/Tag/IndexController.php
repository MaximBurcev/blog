<?php

namespace App\Http\Controllers\Tag;

use App\Http\Controllers\Controller;
use App\Models\Tag;

class IndexController extends Controller
{
    public function __invoke()
    {
        $tags = Tag::hasPublishedPosts()->get();
        $title = 'Теги';
        $description = 'Все теги блога: технологии, языки и инструменты, о которых есть статьи';
        // См. Category\IndexController: пустой листинг закрываем от индексации.
        $robots = $tags->isEmpty() ? 'noindex, follow' : null;

        return view('tags.index', compact('tags', 'title', 'description', 'robots'));
    }
}

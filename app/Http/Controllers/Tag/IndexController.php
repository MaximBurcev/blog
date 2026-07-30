<?php

namespace App\Http\Controllers\Tag;

use App\Http\Controllers\Controller;
use App\Models\Tag;

class IndexController extends Controller
{
    public function __invoke()
    {
        $tags = Tag::all();
        $title = 'Теги';
        $description = 'Все теги блога: технологии, языки и инструменты, о которых есть статьи';

        return view('tags.index', compact('tags', 'title', 'description'));
    }
}

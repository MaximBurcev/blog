<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Models\Category;

class IndexController extends Controller
{
    public function __invoke()
    {
        $categories = Category::hasPublishedPosts()->get();
        $title = 'Категории';
        $description = 'Все категории блога: разделы со статьями и переводами о разработке';
        // Отфильтровав пустые разделы, листинг можно опустошить и сам: в блоге,
        // где всё лежит в черновиках, это страница с заголовком над пустым
        // списком — то же самое «ничего полезного», от которого мы и уходим.
        $robots = $categories->isEmpty() ? 'noindex, follow' : null;

        return view('categories.index', compact('categories', 'title', 'description', 'robots'));
    }
}

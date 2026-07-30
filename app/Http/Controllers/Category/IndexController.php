<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Models\Category;

class IndexController extends Controller
{
    public function __invoke()
    {
        $categories = Category::all();
        $title = 'Категории';
        $description = 'Все категории блога: разделы со статьями и переводами о разработке';

        return view('categories.index', compact('categories', 'title', 'description'));
    }
}

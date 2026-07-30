<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Models\Category;

class ShowController extends Controller
{
    public function __invoke(string $code)
    {
        $category = Category::where('code', $code)->firstOrFail();
        $posts = $category->posts()->published()->paginate(6);
        $title = 'Посты категории '.$category->title;
        $description = 'Посты в категории «'.$category->title.'»';
        $canonical = $posts->currentPage() > 1
            ? $posts->url($posts->currentPage())
            : url()->current();

        return view('categories.show', compact('posts', 'category', 'title', 'description', 'canonical'));
    }
}

<?php

namespace App\Http\Controllers\Category;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Http\Request;

class ShowController extends Controller
{
    public function __invoke(string $code)
    {

        $category = Category::where('code', $code)->firstOrFail();
        $posts = $category->posts()->where('published', 1)->paginate(6);
        return view('categories.show', compact('posts', 'category'));
    }
}

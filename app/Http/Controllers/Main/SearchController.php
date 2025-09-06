<?php

namespace App\Http\Controllers\Main;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\Request;

class SearchController extends Controller
{

    public function __invoke(Request $request)
    {
        $posts = [];
        if ($request->get('q')) {
            $posts = Post::search($request->get('q'))->get();
        }
        $title = 'Поиск';
        return view('main.search', compact('posts',  'title'));
    }
}

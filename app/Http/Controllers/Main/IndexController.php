<?php

namespace App\Http\Controllers\Main;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    const POPULAR_POSTS_COUNT = 4;
    public function __invoke()
    {
        $posts = Post::where('published', 1)->paginate(3);
        $popularPosts = Post::get()->where('published', 1);
        if ($popularPosts->count() > self::POPULAR_POSTS_COUNT) {
            $popularPosts = $popularPosts->rendom(self::POPULAR_POSTS_COUNT);
        }
        $categories = Category::all();
        $tags = Tag::all();
        $title = 'Блог';
        return view('main.index', compact('posts', 'categories', 'popularPosts', 'title', 'tags'));
    }
}

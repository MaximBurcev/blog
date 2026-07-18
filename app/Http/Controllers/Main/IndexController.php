<?php

namespace App\Http\Controllers\Main;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

class IndexController extends Controller
{
    const POPULAR_POSTS_COUNT = 4;

    public function __invoke()
    {
        $posts = Post::where('published', 1)->orderBy('created_at', 'desc')->get();
        $popularPosts = Post::get()->where('published', 1);
        if ($popularPosts->count() > self::POPULAR_POSTS_COUNT) {
            $popularPosts = $popularPosts->random(self::POPULAR_POSTS_COUNT);
        }
        $categories = Category::whereHas('posts', fn ($q) => $q->where('published', 1))->get();
        $tags = Tag::whereHas('posts', fn ($q) => $q->where('published', 1))->get();
        $title = 'Блог';

        return view('main.index', compact('posts', 'categories', 'popularPosts', 'title', 'tags'));
    }
}

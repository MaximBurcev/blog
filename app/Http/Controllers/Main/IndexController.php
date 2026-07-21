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
        $popularPosts = Post::where('published', 1)
            ->withCount(['likes', 'comments'])
            ->orderByRaw('(likes_count + comments_count) DESC')
            ->orderByDesc('created_at')
            ->take(self::POPULAR_POSTS_COUNT)
            ->get();
        $categories = Category::whereHas('posts', fn ($q) => $q->where('published', 1))->get();
        $tags = Tag::whereHas('posts', fn ($q) => $q->where('published', 1))->get();
        $title = 'Блог';
        $description = 'Блог о разработке: новости, статьи и переводы материалов';

        return view('main.index', compact('posts', 'categories', 'popularPosts', 'title', 'tags', 'description'));
    }
}

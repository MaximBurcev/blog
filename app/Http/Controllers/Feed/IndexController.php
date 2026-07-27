<?php

namespace App\Http\Controllers\Feed;

use App\Http\Controllers\Controller;
use App\Models\Post;

class IndexController extends Controller
{
    public function __invoke()
    {
        // without('category') — не используется в feed.index; select() —
        // только колонки, которые реально нужны шаблону (title/code/content
        // для excerpt()/created_at), а не весь Post.
        $posts = Post::published()->without('category')
            ->select('id', 'title', 'code', 'content', 'created_at')
            ->orderBy('created_at', 'desc')
            ->take(30)
            ->get();

        return response()
            ->view('feed.index', compact('posts'))
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}

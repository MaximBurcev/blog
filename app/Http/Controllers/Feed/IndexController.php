<?php

namespace App\Http\Controllers\Feed;

use App\Http\Controllers\Controller;
use App\Models\Post;

class IndexController extends Controller
{
    public function __invoke()
    {
        $posts = Post::where('published', 1)
            ->orderBy('created_at', 'desc')
            ->take(30)
            ->get();

        return response()
            ->view('feed.index', compact('posts'))
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}

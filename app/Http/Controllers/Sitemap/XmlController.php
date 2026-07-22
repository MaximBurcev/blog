<?php

namespace App\Http\Controllers\Sitemap;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

class XmlController extends Controller
{
    public function __invoke()
    {
        $posts = Post::published()->orderBy('updated_at', 'desc')->get();
        $categories = Category::whereHas('posts', fn ($q) => $q->published())->get();
        $tags = Tag::whereHas('posts', fn ($q) => $q->published())->get();

        return response()
            ->view('sitemap.xml.sitemap', compact('posts', 'categories', 'tags'))
            ->header('Content-Type', 'text/xml');
    }
}

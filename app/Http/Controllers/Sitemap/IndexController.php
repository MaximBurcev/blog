<?php

namespace App\Http\Controllers\Sitemap;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

class IndexController extends Controller
{
    public function index()
    {
        $posts = Post::where('published', 1)->get();
        $categories = Category::all();
        $tags = Tag::all();

        $title = 'Карта сайта';
        $description = 'Все разделы, категории, теги и статьи блога на одной странице';

        return view('sitemap.index', compact('posts', 'categories', 'tags', 'title', 'description'));
    }
}

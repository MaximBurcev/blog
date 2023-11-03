<?php

namespace App\Http\Controllers\Sitemap;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function index()
    {
        $posts = Post::where('published', 1)->get();
        $categories = Category::all();
        $tags = Tag::all();
        return view('sitemap.index', compact('posts', 'categories', 'tags'));
    }
}

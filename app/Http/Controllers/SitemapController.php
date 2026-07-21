<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

class SitemapController extends Controller
{
    public function index()
    {
        $posts = Post::orderBy('updated_at', 'desc')->where('published', 1)->get();
        $categories = Category::all();
        $tags = Tag::all();

        return response()
            ->view('sitemap.xml.sitemap', compact('posts', 'categories', 'tags'))
            ->header('Content-Type', 'text/xml');
    }
}

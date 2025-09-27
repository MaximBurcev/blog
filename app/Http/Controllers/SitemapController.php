<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;

class SitemapController extends Controller
{
    public function index()
    {
        $post = Post::orderBy('updated_at', 'desc')->first();
        $post['url'] = url('/sitemap/posts');
        return response()->view('sitemap.xml.index', ['event' => $post, ])->header('Content-Type', 'text/xml');
    }
    public function posts()
    {
        $posts = Post::orderBy('updated_at', 'desc')->where('published', 1)->get();

        return response()
            ->view('sitemap.xml.posts', ['posts' => $posts, ])->header('Content-Type', 'text/xml');
    }
}

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
        // Карте нужны только заголовок, адрес и дата: без сужения колонок
        // сюда на каждый пост приезжали content и content_orig — два LONGTEXT,
        // то есть десяток мегабайт в память ради страницы со списком ссылок.
        $posts = Post::where('published', 1)
            ->select(['id', 'title', 'code', 'updated_at'])
            ->orderByDesc('created_at')
            ->get();
        $categories = Category::select(['id', 'title', 'code'])->get();
        $tags = Tag::select(['id', 'title', 'code'])->get();

        $title = 'Карта сайта';
        $description = 'Все разделы, категории, теги и статьи блога на одной странице';

        return view('sitemap.index', compact('posts', 'categories', 'tags', 'title', 'description'));
    }
}

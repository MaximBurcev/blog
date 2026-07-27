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
        // without('category') — Post::$with грузит category на каждый запрос
        // по умолчанию, тут она не нужна вообще; select() узких колонок
        // вместо всего поста (в т.ч. большого content).
        $posts = Post::published()->without('category')
            ->select('id', 'code', 'updated_at')
            ->orderBy('updated_at', 'desc')->get();
        $categories = Category::whereHas('posts', fn ($q) => $q->published())->select('id', 'code')->get();
        $tags = Tag::whereHas('posts', fn ($q) => $q->published())->select('id', 'code')->get();

        return response()
            ->view('sitemap.xml.sitemap', compact('posts', 'categories', 'tags'))
            ->header('Content-Type', 'text/xml');
    }
}

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
        // is_news нужен Post::permalink(): у новости свой адрес, иначе ссылка
        // ведёт на /posts и отдаёт 301. without('category') — как в XmlController:
        // Post::$with тянет категорию на каждый запрос, а списку ссылок она не
        // нужна (сейчас спасает только то, что category_id нет в select()).
        $posts = Post::published()->without('category')
            ->select(['id', 'title', 'code', 'updated_at', 'is_news'])
            ->orderByDesc('created_at')
            ->get();

        // Раздел без опубликованных постов — пустая страница со списком из
        // нуля ссылок. Отдаёт она 200, в XML-карту не попадает, но поисковик
        // приходит на неё по ссылке отсюда и записывает в малополезные (ровно
        // так было с демо-страницей /counter).
        $categories = Category::hasPublishedPosts()->select(['id', 'title', 'code'])->get();
        $tags = Tag::hasPublishedPosts()->select(['id', 'title', 'code'])->get();

        $title = 'Карта сайта';
        $description = 'Все разделы, категории, теги и статьи блога на одной странице';

        return view('sitemap.index', compact('posts', 'categories', 'tags', 'title', 'description'));
    }
}

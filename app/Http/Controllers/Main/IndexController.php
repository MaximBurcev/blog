<?php

namespace App\Http\Controllers\Main;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

class IndexController extends Controller
{
    private const POPULAR_POSTS_COUNT = 4;

    public function __invoke()
    {
        $posts = Post::published()
            ->select(['id', 'title', 'code', 'preview_image', 'category_id', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->paginate(12);
        $popularPosts = Post::published()
            ->withCount(['views', 'likes', 'comments'])
            // Ранжируем по просмотрам; лайки+комментарии и свежесть — тай-брейкеры,
            // чтобы порядок оставался осмысленным, пока просмотры ещё набираются.
            ->orderByDesc('views_count')
            ->orderByRaw('(likes_count + comments_count) DESC')
            ->orderByDesc('created_at')
            ->take(self::POPULAR_POSTS_COUNT)
            ->get();
        $categories = Category::whereHas('posts', fn ($q) => $q->published())->get();
        $tags = Tag::whereHas('posts', fn ($q) => $q->published())->get();
        $title = 'Блог';

        return view('main.index', compact('posts', 'categories', 'popularPosts', 'title', 'tags'));
    }
}

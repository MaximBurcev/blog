<?php

namespace App\Http\Controllers\Feed;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Support\Facades\Cache;

class IndexController extends Controller
{
    /**
     * Час, как у sitemap.xml: читалки опрашивают ленту постоянно, а новые
     * посты выходят редко — инвалидации по TTL достаточно.
     */
    private const CACHE_TTL = 3600;

    public function __invoke()
    {
        // Кэшируется готовый XML целиком — выборка всегда одна и та же.
        $xml = Cache::remember('feed.xml', self::CACHE_TTL, function () {
            // without('category') — не используется в feed.index; select() —
            // только колонки, которые реально нужны шаблону (title/code/content
            // для excerpt() и content:encoded/created_at), а не весь Post.
            // is_news обязателен: на нём стоит Post::permalink(), а невыбранная
            // колонка молча читается как null — то есть каждая новость получила
            // бы адрес статьи и 301 прямо в <guid isPermaLink="true">, который
            // читалки считают идентификатором записи.
            $posts = Post::published()->without('category')
                ->select('id', 'title', 'code', 'content', 'created_at', 'is_news')
                ->orderBy('created_at', 'desc')
                ->take(30)
                ->get();

            return view('feed.index', compact('posts'))->render();
        });

        return response($xml)
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}

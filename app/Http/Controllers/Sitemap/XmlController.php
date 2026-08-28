<?php

namespace App\Http\Controllers\Sitemap;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Tool;
use Illuminate\Support\Facades\Cache;

class XmlController extends Controller
{
    /**
     * Час: карту перечитывают в основном краулеры, а публикуется блог
     * редко — инвалидации по TTL достаточно, сброс по событию не нужен.
     */
    private const CACHE_TTL = 3600;

    public function __invoke()
    {
        // Кэшируется готовый XML, а не выборки: они всегда идут одним набором,
        // и сериализация моделей в файловый кэш не дешевле рендера шаблона.
        $xml = Cache::remember('sitemap.xml', self::CACHE_TTL, function () {
            // without('category') — Post::$with грузит category на каждый запрос
            // по умолчанию, тут она не нужна вообще; select() узких колонок
            // вместо всего поста (в т.ч. большого content).
            // is_news нужен шаблону: у новости свой адрес (/news/{code}), и в
            // карте должен стоять именно он, иначе поисковик пойдёт по /posts и
            // получит 301 на каждой строке.
            $posts = Post::published()->without('category')
                ->select('id', 'code', 'updated_at', 'is_news')
                ->orderBy('updated_at', 'desc')->get();
            $categories = Category::whereHas('posts', fn ($q) => $q->published())->select('id', 'code')->get();
            $tags = Tag::whereHas('posts', fn ($q) => $q->published())->select('id', 'code')->get();

            // Лента новостей попадает в карту, только когда в ней что-то есть:
            // пустой раздел в sitemap — приглашение поисковику на пустую страницу.
            $hasNews = Post::published()->news()->exists();
            $hasTools = Tool::published()->exists();

            return view('sitemap.xml.sitemap', compact('posts', 'categories', 'tags', 'hasNews', 'hasTools'))->render();
        });

        return response($xml)->header('Content-Type', 'text/xml');
    }
}

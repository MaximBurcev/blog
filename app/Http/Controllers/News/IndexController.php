<?php

namespace App\Http\Controllers\News;

use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Contracts\View\View;

/**
 * Лента новостей. Своих страниц у новости нет: в ней 2–4 строки описания и
 * ссылка на первоисточник, а отдельная страница под такой объём — тонкий
 * контент, за который поисковики понижают весь сайт.
 */
class IndexController extends Controller
{
    private const PER_PAGE = 20;

    public function __invoke(): View
    {
        $news = News::published()
            // select() явно: в таблице лежат ещё и оригиналы (title_orig,
            // summary_orig), они нужны только админке.
            ->select(['id', 'url', 'title', 'summary', 'source_host', 'created_at'])
            ->latest('created_at')
            ->paginate(self::PER_PAGE);

        return view('news.index', [
            'news' => $news,
            'title' => 'Новости',
            'description' => 'Новости и анонсы мира PHP: релизы фреймворков, инструменты и события — коротко, на русском.',
        ]);
    }
}

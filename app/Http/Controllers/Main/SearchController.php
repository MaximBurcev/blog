<?php

namespace App\Http\Controllers\Main;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    /**
     * Максимальная длина запроса. Меилисёрч всё равно отбрасывает хвост, а
     * без ограничения в него уходила строка любого размера прямо из адресной
     * строки.
     */
    private const MAX_QUERY_LENGTH = 200;

    private const PER_PAGE = 12;

    public function __invoke(Request $request)
    {
        $query = mb_substr(trim((string) $request->get('q')), 0, self::MAX_QUERY_LENGTH);

        $posts = $this->emptyPage($request);
        $searchFailed = false;

        if ($query !== '') {
            try {
                // Фильтр по published — на случай, если пост сняли с публикации
                // уже после индексации: показывать такой в выдаче нельзя, ссылка
                // ведёт на 404 (Post\ShowController отдаёт только опубликованные).
                $posts = Post::search($query)
                    ->where('published', true)
                    // Выдача рисует только карточку, а без сужения колонок с
                    // каждым результатом тянулись content и content_orig — два
                    // LONGTEXT на строку.
                    ->query(fn (Builder $builder) => $builder
                        ->select(['id', 'title', 'code', 'preview_image', 'category_id'])
                        ->with('category:id,title,code'))
                    ->paginate(self::PER_PAGE)
                    ->withQueryString();
            } catch (\Throwable $exception) {
                // Meilisearch — отдельный контейнер, и его падение не должно
                // ронять публичную страницу пятисоткой: показываем сообщение.
                Log::warning('Поиск недоступен', [
                    'error' => $exception->getMessage(),
                    'class' => get_class($exception),
                ]);

                $searchFailed = true;
            }
        }

        $title = 'Поиск';
        // Страницы результатов индексировать не нужно: ?q=… порождает
        // бесконечное множество почти пустых дублей. follow — чтобы краулер
        // всё же прошёл по ссылкам на сами посты.
        $robots = 'noindex, follow';

        return view('main.search', compact('posts', 'title', 'robots', 'query', 'searchFailed'));
    }

    /**
     * Пустая страница выдачи: шаблон работает с пагинатором единообразно и
     * при пустом запросе, и при недоступном поиске.
     */
    private function emptyPage(Request $request): LengthAwarePaginator
    {
        return new Paginator([], 0, self::PER_PAGE, $request->integer('page', 1), [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);
    }
}

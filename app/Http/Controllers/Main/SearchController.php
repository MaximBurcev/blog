<?php

namespace App\Http\Controllers\Main;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\SearchQuery;
use App\Support\BotDetector;
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
        // is_string обязателен: ?q[]=1 приходит массивом, и приведение (string)
        // давало warning «Array to string conversion», а шаблон, печатавший
        // сырое request('q'), ронял страницу пятисоткой на e(array).
        $raw = $request->get('q');
        $query = is_string($raw) ? mb_substr(trim($raw), 0, self::MAX_QUERY_LENGTH) : '';

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
                    //
                    // is_news в списке обязателен: карточка строит адрес через
                    // permalink(), а без колонки флаг молча читается как null,
                    // и каждая найденная новость ведёт на адрес статьи, то
                    // есть через 301. С индексацией оригинала находиться стало
                    // заметно больше материалов, новостей в том числе.
                    ->query(fn (Builder $builder) => $builder
                        ->select(['id', 'title', 'code', 'preview_image', 'category_id', 'is_news'])
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

            // Запрос записываем только если поиск отработал: при упавшем
            // Meilisearch ноль результатов означает «сервис лежит», а не «на
            // сайте такого нет», и в отчёте «искали и не нашли» это выглядело
            // бы как пробел в контенте.
            if (! $searchFailed) {
                $this->remember($request, $query, $posts->total());
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
     * Складывает запрос в отчёт «что искали».
     *
     * Пагинация: считаем только первую страницу, иначе один запрос, пролистанный
     * читателем до конца, попадёт в отчёт столько раз, сколько было страниц.
     *
     * Ошибка записи не должна ронять выдачу: страница поиска — публичная, а
     * сбор статистики здесь дело десятое. Поэтому try/catch, а не надежда на то,
     * что таблица всегда доступна.
     */
    private function remember(Request $request, string $query, int $resultsCount): void
    {
        if ($request->integer('page', 1) > 1) {
            return;
        }

        // Робот ищет не потому, что ему чего-то не хватает: /search закрыт в
        // robots.txt, но послушны не все, а краулер, прошедший по ссылкам с
        // ?q=, наполнил бы отчёт «искали и не нашли» собственными выдумками.
        // Тот же детектор, что отсеивает роботов в post_views.
        if (BotDetector::isBot($request->userAgent())) {
            return;
        }

        $normalized = SearchQuery::normalize($query);

        if ($normalized === null) {
            return;
        }

        try {
            SearchQuery::create([
                'query' => $normalized,
                'results_count' => $resultsCount,
            ]);
        } catch (\Throwable $exception) {
            // Только класс, без getMessage(): у QueryException в сообщении
            // лежит SQL с подставленными биндингами, то есть сам поисковый
            // запрос. Он уехал бы в laravel.log, который читается через
            // /log-viewer, — при том что вся затея строится на обезличенности.
            Log::warning('Не удалось записать поисковый запрос', [
                'exception' => $exception::class,
            ]);
        }
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

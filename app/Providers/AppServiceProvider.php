<?php

namespace App\Providers;

use App\Service\HtmlSanitizerService;
use Carbon\Carbon;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Синглтон: HtmlSanitizerService держит внутри собранный HTMLPurifier,
        // а его сборка — разбор всего набора разрешённых тегов и атрибутов.
        // Через app() сервис создаётся заново на каждый вызов, а вызовов на
        // сохранение поста два (content и content_orig) и по сотне подряд
        // в ResanitizePostsCommand.
        $this->app->singleton(HtmlSanitizerService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('ru_RU');
        Paginator::useBootstrapFive();

        // Гейт viewLogViewer объявлен один раз — в AuthServiceProvider.
        // Здесь была вторая копия с не-nullable $user: провайдеры грузятся по
        // порядку из config/app.php, и если бы она объявлялась последней,
        // гость на /log-viewer получал бы fatal вместо 403.

        View::composer('layouts.main', function ($view) {
            $data = $view->getData();

            $pageTitle = $data['title'] ?? null;

            $view->with('title', $this->documentTitle($pageTitle));
            // og:title без имени сайта и без «страница N» — в соцсетях
            // название ресурса и так выводится отдельной строкой.
            $view->with('ogTitle', $pageTitle ?? config('seo.default_title'));
            $view->with('description', $this->pageDescription($data['description'] ?? null));
            $view->with('ogImage', $data['ogImage'] ?? asset(config('seo.default_image')));
            $view->with('ogType', $data['ogType'] ?? 'website');
            $view->with('ogUrl', $data['ogUrl'] ?? url()->current());
            // url()->current() отбрасывает query-строку — это нужное поведение
            // для /search?q=…, но листинги обязаны канонизироваться на свою
            // страницу пагинации, иначе страницы 2+ схлопнутся в первую.
            // Такие контроллеры передают $canonical явно.
            $view->with('canonical', $data['canonical'] ?? url()->current());
            $view->with('robots', $data['robots'] ?? null);
            $view->with('articleMeta', $data['articleMeta'] ?? null);
        });
    }

    /**
     * Заголовок вкладки: «Название страницы — Имя сайта», плюс номер
     * страницы для пагинации — иначе ?page=2 и далее уходят в индекс с
     * точно таким же title, как первая страница листинга.
     */
    private function documentTitle(?string $pageTitle): string
    {
        $siteName = config('app.name');

        if ($pageTitle === null || $pageTitle === $siteName) {
            return $siteName;
        }

        $page = (int) request()->query('page', 1);
        $suffix = $page > 1 ? " — страница {$page}" : '';

        return $pageTitle.$suffix.' — '.$siteName;
    }

    /**
     * Описание страницы для meta description.
     *
     * Пустая строка отсекается наравне с null: `?? ` её не ловит, а
     * Post::excerpt() возвращает '' для статьи, в которой кроме кода и
     * таблиц ничего нет — в разметку уходил пустой content="".
     *
     * Номер страницы дописывается по той же причине, что и в title: без него
     * все страницы пагинации листинга уходят в индекс с одинаковым
     * описанием, и Вебмастер помечает их как некорректно заполненные.
     */
    private function pageDescription(?string $description): string
    {
        $description = trim((string) $description);

        if ($description === '') {
            $description = config('seo.default_description');
        }

        $page = (int) request()->query('page', 1);

        return $page > 1 ? $description.' — страница '.$page : $description;
    }
}

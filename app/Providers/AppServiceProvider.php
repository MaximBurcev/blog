<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Service\HtmlSanitizerService;
use Carbon\Carbon;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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

        // Без этого gate opcodesio/log-viewer вообще не проверяет доступ
        // (LogViewerService::auth() — no-op при отсутствии и callback, и
        // gate'а) — /log-viewer был бы открыт кому угодно, а логи содержат
        // стектрейсы/SQL/payload'ы запросов.
        Gate::define('viewLogViewer', fn ($user) => $user->role === UserRole::Admin);

        View::composer('layouts.main', function ($view) {
            $data = $view->getData();

            $pageTitle = $data['title'] ?? null;

            $view->with('title', $this->documentTitle($pageTitle));
            // og:title без имени сайта и без «страница N» — в соцсетях
            // название ресурса и так выводится отдельной строкой.
            $view->with('ogTitle', $pageTitle ?? config('seo.default_title'));
            $view->with('description', $data['description'] ?? config('seo.default_description'));
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
}

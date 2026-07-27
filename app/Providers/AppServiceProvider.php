<?php

namespace App\Providers;

use App\Enums\UserRole;
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
        //
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

            $view->with('title', $data['title'] ?? config('seo.default_title'));
            $view->with('description', $data['description'] ?? config('seo.default_description'));
            $view->with('ogImage', $data['ogImage'] ?? asset(config('seo.default_image')));
            $view->with('ogType', $data['ogType'] ?? 'website');
            $view->with('ogUrl', $data['ogUrl'] ?? url()->current());
        });
    }
}

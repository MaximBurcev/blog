<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('ru_RU');
        Paginator::useBootstrapFive();

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

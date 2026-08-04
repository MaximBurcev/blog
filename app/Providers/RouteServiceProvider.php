<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Форма комментариев открыта гостям (auth не требуется), а honeypot
        // ловит только примитивных ботов — короткий burst-лимит режет
        // скриптовый спам, часовой добивает медленный/распределённый обход.
        RateLimiter::for('comments', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            // Разные ->by()-ключи обязательны: одинаковый ключ у обоих
            // лимитеров заставляет их делить один счётчик в кэше и удваивать
            // друг друга при каждом запросе вместо независимой проверки.
            return [
                Limit::perMinute(3)->by('comments-minute:'.$key),
                Limit::perHour(10)->by('comments-hour:'.$key),
            ];
        });

        // Регистрация и запрос сброса пароля: Auth::routes() не вешает throttle
        // ни на один маршрут, а брокер паролей ограничивает только повторную
        // отправку на ОДИН адрес (config/auth.php passwords.throttle). Без лимита
        // по IP это спам-регистрации и рассылка писем сброса на чужие ящики
        // через нашу инфраструктуру.
        RateLimiter::for('auth-sensitive', function (Request $request) {
            return [
                Limit::perMinute(5)->by('auth-minute:'.$request->ip()),
                Limit::perHour(20)->by('auth-hour:'.$request->ip()),
            ];
        });

        // Лайк порождает запись в БД и broadcast-джобу на каждый запрос,
        // поиск — обращение к Meilisearch. Лимит на пользователя, а для гостя
        // (поиск открыт всем) — на IP.
        RateLimiter::for('interactions', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}

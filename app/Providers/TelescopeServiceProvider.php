<?php

namespace App\Providers;

use App\Enums\UserRole;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        Telescope::filter(function (IncomingEntry $entry) {
            if ($this->app->environment('local')) {
                return true;
            }

            return $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        // Скрываем во всех окружениях, включая local: на dev-контуре Telescope пишет
        // вообще всё (см. filter() ниже), и заголовок Cookie — это значение живой сессии,
        // которое затем уезжает в дампы БД и бэкапы.
        // Пароли обязаны быть здесь, а не только _token. RequestWatcher пишет
        // тело запроса целиком, а filter() ниже пропускает isFailedRequest() —
        // это в том числе 429 от throttle:auth-sensitive на /login и /register.
        // То есть каждая заблокированная попытка подбора складывала в
        // telescope_entries пару email + пароль открытым текстом, а в local
        // (ранний return true) туда попадали вообще все успешные логины.
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
            'new_password',
            'new_password_confirmation',
        ]);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            return $user->role === UserRole::Admin;
        });
    }
}

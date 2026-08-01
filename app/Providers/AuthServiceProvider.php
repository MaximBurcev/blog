<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Без этого gate пакет log-viewer пускает к логам кого угодно:
        // AuthorizeLogViewer вызывает LogViewer::auth(), а тот проверяет
        // доступ, только когда gate объявлен (иначе — молча пропускает).
        Gate::define('viewLogViewer', function (?User $user) {
            return $user?->role === UserRole::Admin;
        });
    }
}

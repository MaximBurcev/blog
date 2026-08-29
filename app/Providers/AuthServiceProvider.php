<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Release;
use App\Models\SiteSelector;
use App\Models\Tag;
use App\Models\Tool;
use App\Models\User;
use App\Policies\CategoryPolicy;
use App\Policies\CommentPolicy;
use App\Policies\PostPolicy;
use App\Policies\ReleasePolicy;
use App\Policies\SiteSelectorPolicy;
use App\Policies\TagPolicy;
use App\Policies\ToolPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * Роли Admin/Editor/Reader разводятся здесь: контентные ресурсы —
     * Admin+Editor (ContentPolicy), пользователи — только Admin (UserPolicy).
     * Filament 3 подхватывает эти политики автоматически, явных canViewAny
     * в ресурсах не требуется.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Post::class => PostPolicy::class,
        Category::class => CategoryPolicy::class,
        Tag::class => TagPolicy::class,
        Tool::class => ToolPolicy::class,
        Comment::class => CommentPolicy::class,
        SiteSelector::class => SiteSelectorPolicy::class,
        Release::class => ReleasePolicy::class,
        User::class => UserPolicy::class,
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

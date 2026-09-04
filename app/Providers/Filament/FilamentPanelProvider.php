<?php

namespace App\Providers\Filament;

use App\Filament\Analytics\Widgets\CommentsAndLikes;
use App\Filament\Analytics\Widgets\LlmUsage;
use App\Filament\Analytics\Widgets\NewsArticlesSplit;
use App\Filament\Analytics\Widgets\PostViewsChart;
use App\Filament\Analytics\Widgets\PostViewsOverview;
use App\Filament\Analytics\Widgets\PublishingPace;
use App\Filament\Analytics\Widgets\ReaderRetention;
use App\Filament\Analytics\Widgets\ReadingDepth;
use App\Filament\Analytics\Widgets\SearchQueries;
use App\Filament\Analytics\Widgets\TopPostsByViews;
use App\Filament\Analytics\Widgets\TrafficSources;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class FilamentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('filament')
            // Адрес панели настраивается (config/admin.php), дефолт —
            // исторический /filament. Меняется через ADMIN_PANEL_PATH в .env.
            ->path(config('admin.panel_path', 'filament'))
            ->login()
            ->favicon(asset('favicon.svg'))
            // По умолчанию Filament ограничивает контент 7xl (80rem) — широкая
            // таблица постов из-за этого уезжала в горизонтальный скролл.
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            // Виджеты страницы «Аналитика» намеренно лежат вне
            // app/Filament/Widgets: всё, что попадает в discoverWidgets выше,
            // Filament автоматически выводит на дашборде, а им место на своей
            // странице с фильтром периода.
            //
            // Но регистрация в Livewire нужна всё равно. Первый рендер
            // проходит и без неё (@livewire() резолвит по имени класса), а вот
            // обратный путь — snapshot с именем
            // «app.filament.analytics.widgets.…» → класс — работает только
            // через реестр компонентов. Без этой строки любое действие внутри
            // виджета (смена периода, сортировка и пагинация топа) отвечало бы
            // ComponentNotFoundException, то есть страница была бы полностью
            // неинтерактивной. livewireComponents() даёт только алиас и в
            // Filament::getWidgets() не пишет — на дашборде они не появятся.
            ->livewireComponents([
                PostViewsOverview::class,
                ReadingDepth::class,
                ReaderRetention::class,
                PostViewsChart::class,
                TrafficSources::class,
                PublishingPace::class,
                NewsArticlesSplit::class,
                LlmUsage::class,
                CommentsAndLikes::class,
                SearchQueries::class,
                TopPostsByViews::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

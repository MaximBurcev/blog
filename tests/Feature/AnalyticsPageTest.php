<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Analytics\Widgets\PostViewsChart;
use App\Filament\Analytics\Widgets\PostViewsOverview;
use App\Filament\Analytics\Widgets\TopPostsByViews;
use App\Filament\Pages\Analytics;
use App\Models\Category;
use App\Models\Post;
use App\Models\PostView;
use App\Models\User;
use App\Providers\Filament\FilamentPanelProvider;
use App\Support\AnalyticsPeriod;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Страница «Аналитика» и её виджеты: post_views пишется с 29.07.2026, но до
 * сих пор читался только счётчиком под заголовком статьи.
 */
class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_available_for_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(Analytics::getUrl())
            ->assertSuccessful();
    }

    public function test_page_is_closed_for_readers(): void
    {
        $reader = User::factory()->create(['role' => UserRole::Reader]);

        $this->actingAs($reader)
            ->get(Analytics::getUrl())
            ->assertForbidden();
    }

    /**
     * Виджеты аналитики лежат вне app/Filament/Widgets намеренно: та
     * директория раздаётся автодискавери, и всё из неё Filament выводит на
     * дашборде, где нет фильтра периода.
     */
    public function test_analytics_widgets_are_not_registered_on_dashboard(): void
    {
        $panelWidgets = Filament::getDefaultPanel()->getWidgets();

        $this->assertNotContains(PostViewsOverview::class, $panelWidgets);
        $this->assertNotContains(PostViewsChart::class, $panelWidgets);
        $this->assertNotContains(TopPostsByViews::class, $panelWidgets);
    }

    /**
     * Обратная сторона того же решения: вне автодискавери Livewire не узнаёт
     * компонент по имени из snapshot, и любое действие внутри виджета (смена
     * периода, сортировка и пагинация топа) отвечает ComponentNotFoundException
     * вместо HTML. Первый рендер при этом проходит — поэтому баг ловится
     * только такой проверкой, а не рендером страницы.
     *
     * @see FilamentPanelProvider::panel()
     */
    public function test_analytics_widgets_are_resolvable_by_livewire_name(): void
    {
        $registry = app(ComponentRegistry::class);

        foreach ([PostViewsOverview::class, PostViewsChart::class, TopPostsByViews::class] as $widget) {
            $this->assertSame(
                $widget,
                $registry->getClass($registry->getName($widget)),
                "Виджет {$widget} не зарегистрирован в Livewire — интерактив страницы работать не будет",
            );
        }
    }

    public function test_page_passes_selected_period_to_widgets(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $page = Livewire::actingAs($admin)
            ->test(Analytics::class)
            ->assertSuccessful()
            ->set('filters.period', 7)
            ->assertSuccessful()
            ->assertSet('filters.period', 7);

        // Именно через getWidgetData() выбранный период доезжает до виджетов:
        // без него смена фильтра перерисовывала бы один только селект.
        $this->assertSame(['filters' => ['period' => 7]], $page->instance()->getWidgetData());
    }

    public function test_period_filter_falls_back_to_default_on_unknown_value(): void
    {
        // Значение приходит из query-string (#[Url]), поэтому в него может
        // прилететь что угодно: и мусор, и период, который выльется в скан
        // всей таблицы просмотров.
        $this->assertSame(AnalyticsPeriod::DEFAULT_DAYS, AnalyticsPeriod::days(['period' => '100000']));
        $this->assertSame(AnalyticsPeriod::DEFAULT_DAYS, AnalyticsPeriod::days(['period' => 'сегодня']));
        $this->assertSame(AnalyticsPeriod::DEFAULT_DAYS, AnalyticsPeriod::days(null));
        $this->assertSame(7, AnalyticsPeriod::days(['period' => '7']));
    }

    public function test_period_includes_today_entirely(): void
    {
        // Неделя — это сегодня и шесть предыдущих суток, иначе на графике
        // оказывалось бы восемь точек вместо семи.
        $this->assertSame(
            now()->subDays(6)->startOfDay()->toDateTimeString(),
            AnalyticsPeriod::startsAt(['period' => 7])->toDateTimeString(),
        );
    }

    public function test_compared_windows_have_equal_length(): void
    {
        // Текущее окно — это N-1 полных суток плюс кусок сегодняшнего дня.
        // Если предыдущее окно взять целыми сутками, утром при ровном трафике
        // плитка показывала бы падение на пустом месте.
        $filters = ['period' => 7];

        $current = AnalyticsPeriod::startsAt($filters)->diffInSeconds(now());
        $previous = AnalyticsPeriod::previousStartsAt($filters)
            ->diffInSeconds(AnalyticsPeriod::previousEndsAt($filters));

        $this->assertEqualsWithDelta($current, $previous, 1);
    }

    public function test_period_labels_match_the_filter_options(): void
    {
        // Селект показывает «Неделя», плитки — «За неделю»: одно и то же
        // название периода, а не «За 7 дней» рядом с «Неделя».
        $this->assertSame(['Неделя', 'Месяц', 'Квартал'], array_values(AnalyticsPeriod::options()));
        $this->assertSame('неделю', AnalyticsPeriod::label(['period' => 7]));
        $this->assertSame('предыдущую неделю', AnalyticsPeriod::previousLabel(['period' => 7]));
    }

    public function test_overview_counts_only_views_inside_period(): void
    {
        $post = $this->createPost('Пост с трафиком', 'post-with-traffic');

        $this->recordViews($post, count: 3, daysAgo: 0);
        $this->recordViews($post, count: 7, daysAgo: 4);
        // За границей недельного окна — в плитку периода попасть не должны,
        // но в «Всего» обязаны.
        $this->recordViews($post, count: 41, daysAgo: 20);

        $stats = $this->statsFor(['period' => 7]);

        $this->assertSame('3', $stats['Сегодня']);
        $this->assertSame('10', $stats['За неделю']);
        $this->assertSame('51', $stats['Всего просмотров']);
        // Каждый просмотр в фикстуре получает собственный session_hash.
        $this->assertSame('10', $stats['Читателей за неделю']);
    }

    public function test_overview_compares_period_with_the_previous_one(): void
    {
        $post = $this->createPost('Пост с динамикой', 'post-with-trend');

        // Текущая неделя против предыдущей: 6 против 3, то есть рост вдвое.
        $this->recordViews($post, count: 6, daysAgo: 1);
        $this->recordViews($post, count: 3, daysAgo: 8);

        $descriptions = $this->statDescriptionsFor(['period' => 7]);

        $this->assertStringContainsString('+100%', $descriptions['За неделю']);
        $this->assertStringContainsString('предыдущую неделю', $descriptions['За неделю']);
    }

    public function test_top_posts_shows_only_posts_read_within_period(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $read = $this->createPost('Читаемый пост', 'read-post');
        $stale = $this->createPost('Забытый пост', 'stale-post');
        $never = $this->createPost('Пост без просмотров', 'never-read-post');

        $this->recordViews($read, count: 5, daysAgo: 1);
        $this->recordViews($stale, count: 100, daysAgo: 60);

        Livewire::actingAs($admin)
            ->test(TopPostsByViews::class, ['filters' => ['period' => 7]])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$read])
            // У обоих просмотры есть, но не в выбранном окне: в топе периода
            // им не место, иначе список превращается в общий рейтинг за всё
            // время и фильтр перестаёт что-либо значить.
            ->assertCanNotSeeTableRecords([$stale, $never]);
    }

    public function test_top_posts_are_sorted_by_views_within_period(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $popular = $this->createPost('Популярный', 'popular-post');
        $modest = $this->createPost('Скромный', 'modest-post');

        $this->recordViews($modest, count: 2, daysAgo: 1);
        $this->recordViews($popular, count: 9, daysAgo: 1);
        // Старый хвост «скромного» не должен поднимать его в топе периода.
        $this->recordViews($modest, count: 500, daysAgo: 45);

        Livewire::actingAs($admin)
            ->test(TopPostsByViews::class, ['filters' => ['period' => 7]])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$popular, $modest], inOrder: true);
    }

    public function test_chart_renders_a_point_for_every_day_of_the_period(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $post = $this->createPost('Пост для графика', 'chart-post');

        $this->recordViews($post, count: 1, daysAgo: 2);

        $widget = Livewire::actingAs($admin)
            ->test(PostViewsChart::class, ['filters' => ['period' => 7]])
            ->assertSuccessful()
            ->instance();

        $data = $this->callProtected($widget, 'getData');

        // Дни без просмотров Trend дорисовывает нулями — провалы не должны
        // схлопываться, иначе график врёт о динамике.
        $this->assertCount(7, $data['labels']);
        $this->assertSame(1, (int) collect($data['datasets'][0]['data'])->sum());
    }

    /**
     * Значения плиток, а не подстроки в HTML: разметка Stat набита цифрами
     * (пути иконок, классы сетки), и assertSee('10') проходил бы даже на
     * сломанном счётчике.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function statsFor(array $filters): array
    {
        return $this->mapStats($filters, fn (Stat $stat): string => (string) $stat->getValue());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function statDescriptionsFor(array $filters): array
    {
        return $this->mapStats($filters, fn (Stat $stat): string => (string) $stat->getDescription());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function mapStats(array $filters, callable $value): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $widget = Livewire::actingAs($admin)
            ->test(PostViewsOverview::class, ['filters' => $filters])
            ->assertSuccessful()
            ->instance();

        $stats = [];

        foreach ($this->callProtected($widget, 'getStats') as $stat) {
            $stats[(string) $stat->getLabel()] = $value($stat);
        }

        return $stats;
    }

    private function callProtected(object $widget, string $method): mixed
    {
        return (new ReflectionMethod($widget, $method))->invoke($widget);
    }

    private function createPost(string $title, string $code): Post
    {
        return Post::withoutSyncingToSearch(function () use ($title, $code): Post {
            $category = Category::firstOrCreate(
                ['code' => 'php'],
                ['title' => 'PHP'],
            );

            return Post::create([
                'title' => $title,
                'code' => $code,
                'content' => 'content',
                'published' => 1,
                'category_id' => $category->id,
            ]);
        });
    }

    private function recordViews(Post $post, int $count, int $daysAgo): void
    {
        // Начало суток: окна сравнения обрезаны текущим временем суток, и
        // просмотр должен попадать в своё окно, в котором бы часу ни гонялись
        // тесты.
        $viewedAt = now()->subDays($daysAgo)->setTime(0, 0);

        for ($i = 0; $i < $count; $i++) {
            PostView::create([
                'post_id' => $post->id,
                'session_hash' => hash('sha256', $post->id.'-'.$daysAgo.'-'.$i),
                'viewed_at' => $viewedAt,
            ]);
        }
    }
}

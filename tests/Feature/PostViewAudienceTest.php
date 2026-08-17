<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Analytics\Widgets\PostViewsOverview;
use App\Filament\Analytics\Widgets\TrafficSources;
use App\Models\Category;
use App\Models\Post;
use App\Models\PostView;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Кто именно попадает в статистику и откуда он пришёл.
 *
 * До 17.08.2026 просмотром считался любой GET страницы поста: на проде это
 * давало 589 сессий с 311 адресов при 1212 записях, у отдельных IP до 39
 * сессий — то есть заметная доля «читателей» была краулерами. Счётчик под
 * статьёй завышал число читателей, а «Топ постов» ранжировал материалы по
 * интересу поисковых роботов.
 */
class PostViewAudienceTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function test_crawler_visit_is_recorded_but_not_counted_as_a_reader(): void
    {
        $post = $this->createPost();

        $this->withHeaders(['User-Agent' => self::BOT])
            ->get($post->permalink())
            ->assertOk();

        // Запись сохраняется: детект по UA неточен, и ошибку нужно уметь
        // пересмотреть задним числом.
        $this->assertSame(1, PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->count());
        $this->assertTrue(PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->first()->is_bot);

        // Но ни в счётчике под статьёй, ни в аналитике её нет.
        $this->assertSame(0, $post->viewsCount());
        $this->assertSame(0, PostView::count());
    }

    public function test_reader_visit_is_counted(): void
    {
        $post = $this->createPost();

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->get($post->permalink())
            ->assertOk();

        $this->assertSame(1, $post->viewsCount());
    }

    public function test_overview_tiles_ignore_crawlers(): void
    {
        // Регрессия на конкретный способ промахнуться: PostViewsOverview
        // читает таблицу через DB::table(), мимо Eloquent, поэтому глобальный
        // скоуп PostView::HUMANS_ONLY туда не достаёт и условие приходится
        // держать вручную. Рендером страницы это не ловится.
        $post = $this->createPost();

        $this->recordViews($post, count: 3, isBot: false);
        $this->recordViews($post, count: 17, isBot: true);

        $stats = $this->statsFor(['period' => 7]);

        $this->assertSame('3', $stats['За неделю']);
        $this->assertSame('3', $stats['Всего просмотров']);
    }

    public function test_referer_is_stored_as_host_without_path(): void
    {
        $post = $this->createPost();

        $this->withHeaders([
            'User-Agent' => self::BROWSER,
            // Путь и query чужой страницы — чужие данные, храниться не должны.
            'referer' => 'https://www.Yandex.ru/search/?text=секретный+запрос',
        ])->get($post->permalink())->assertOk();

        $view = PostView::firstOrFail();

        $this->assertSame('yandex.ru', $view->referer_host);
    }

    public function test_internal_navigation_is_told_apart_from_a_direct_visit(): void
    {
        $post = $this->createPost();

        $this->withHeaders([
            'User-Agent' => self::BROWSER,
            'referer' => url('/'),
        ])->get($post->permalink())->assertOk();

        // Внутренний переход пишется своим хостом, а не NULL. Свалив их в одно
        // значение, виджет назвал бы прямыми заходами всю навигацию по блогу —
        // на сайте с перелинковкой это большинство просмотров, и разделить их
        // потом было бы нечем.
        $this->assertSame(
            parse_url((string) config('app.url'), PHP_URL_HOST),
            PostView::firstOrFail()->referer_host,
        );
    }

    public function test_traffic_sources_widget_separates_internal_navigation(): void
    {
        $post = $this->createPost();
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        $this->recordViews($post, count: 6, isBot: false, referer: $own);
        $this->recordViews($post, count: 2, isBot: false, referer: 'habr.com');
        $this->recordViews($post, count: 3, isBot: false);

        $data = $this->widgetData(['period' => 7]);

        // Внутренние переходы не должны попадать ни в источники, ни в прямые.
        $this->assertSame(['habr.com'], $data['top']->pluck('referer_host')->all());
        $this->assertSame(6, $data['internal']['views']);
        $this->assertSame(3, $data['direct']['views']);
    }

    public function test_widget_ignores_views_recorded_before_referer_was_collected(): void
    {
        $post = $this->createPost();

        // У этих записей referer_host = NULL означает «неизвестно»: колонки
        // тогда не существовало. Показать их прямыми заходами значит выдумать
        // источник — на длинном периоде они бы весь отчёт и составили.
        $this->recordViews($post, count: 40, isBot: false, at: '2026-08-01');
        $this->recordViews($post, count: 3, isBot: false);

        $data = $this->widgetData(['period' => 90]);

        $this->assertSame(3, $data['direct']['views']);
        $this->assertTrue($data['truncated']);
    }

    public function test_malformed_referer_host_is_not_stored(): void
    {
        $post = $this->createPost();

        // parse_url отдаёт хостом и такое. В админке оно экранируется, но
        // храниться как «домен» не должно: строка переживёт вывод и всплывёт
        // в экспорте или логах.
        $this->withHeaders([
            'User-Agent' => self::BROWSER,
            'referer' => 'http://<script>alert(1)</script>/x',
        ])->get($post->permalink())->assertOk();

        $this->assertNull(PostView::firstOrFail()->referer_host);
    }

    public function test_trailing_dot_and_www_collapse_to_one_source(): void
    {
        $post = $this->createPost();

        foreach (['https://habr.com/p/1', 'https://www.habr.com./p/2', 'https://HABR.com/p/3'] as $i => $referer) {
            $this->withHeaders(['User-Agent' => self::BROWSER, 'referer' => $referer])
                ->get($post->permalink().'?i='.$i)
                ->assertOk();

            // Дедуп считает визит по сессии, а тест хочет три записи.
            $this->flushSession();
        }

        // Иначе разбить свой же топ источников можно одним заголовком.
        $this->assertSame(['habr.com'], PostView::pluck('referer_host')->unique()->values()->all());
    }

    public function test_campaign_tags_are_stored_and_bounded(): void
    {
        $post = $this->createPost();

        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->get($post->permalink().'?utm_source=telegram&utm_medium=post&utm_campaign='.str_repeat('x', 200))
            ->assertOk();

        $view = PostView::firstOrFail();

        $this->assertSame('telegram', $view->utm_source);
        $this->assertSame('post', $view->utm_medium);
        // Метки приходят из адресной строки, то есть их пишет кто угодно.
        $this->assertSame(100, mb_strlen($view->utm_campaign));
    }

    public function test_array_campaign_tag_does_not_break_the_view(): void
    {
        $post = $this->createPost();

        // ?utm_source[]=a приходит массивом: без проверки типа запись просмотра
        // падала бы прямо на показе статьи.
        $this->withHeaders(['User-Agent' => self::BROWSER])
            ->get($post->permalink().'?utm_source[]=a&utm_source[]=b')
            ->assertOk();

        $this->assertNull(PostView::firstOrFail()->utm_source);
    }

    public function test_dedup_window_covers_crawlers_too(): void
    {
        $post = $this->createPost();

        // Дедуп ищет прошлый визит по базе. Если бы поиск шёл с включённым
        // скоупом «только люди», помеченный робот каждый раз выглядел бы новым
        // посетителем и плодил строки.
        foreach (range(1, 3) as $ignored) {
            $this->withHeaders(['User-Agent' => self::BOT])->get($post->permalink())->assertOk();
        }

        $this->assertSame(1, PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->count());
    }

    public function test_traffic_sources_widget_separates_direct_visits(): void
    {
        $post = $this->createPost();

        $this->recordViews($post, count: 2, isBot: false, referer: 'habr.com');
        $this->recordViews($post, count: 5, isBot: false, referer: 'yandex.ru');
        $this->recordViews($post, count: 4, isBot: false);
        // Робот с реферером в отчёт попасть не должен.
        $this->recordViews($post, count: 9, isBot: true, referer: 'habr.com');

        $data = $this->widgetData(['period' => 7]);

        $this->assertSame(['yandex.ru', 'habr.com'], $data['top']->pluck('referer_host')->all());
        $this->assertSame(5, (int) $data['top']->first()->views);
        $this->assertSame(4, $data['direct']['views']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function widgetData(array $filters): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $widget = Livewire::actingAs($admin)
            ->test(TrafficSources::class, ['filters' => $filters])
            ->assertSuccessful()
            ->instance();

        return (new ReflectionMethod($widget, 'getViewData'))->invoke($widget);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function statsFor(array $filters): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $widget = Livewire::actingAs($admin)
            ->test(PostViewsOverview::class, ['filters' => $filters])
            ->assertSuccessful()
            ->instance();

        $stats = [];

        foreach ((new ReflectionMethod($widget, 'getStats'))->invoke($widget) as $stat) {
            /** @var Stat $stat */
            $stats[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        return $stats;
    }

    private function recordViews(Post $post, int $count, bool $isBot, ?string $referer = null, ?string $at = null): void
    {
        $viewedAt = $at === null ? now()->setTime(0, 0) : Carbon::parse($at);

        for ($i = 0; $i < $count; $i++) {
            PostView::create([
                'post_id' => $post->id,
                'session_hash' => hash('sha256', $post->id.'-'.$referer.'-'.(int) $isBot.'-'.$at.'-'.$i),
                'is_bot' => $isBot,
                'referer_host' => $referer,
                'viewed_at' => $viewedAt,
            ]);
        }
    }

    private function createPost(): Post
    {
        return Post::withoutSyncingToSearch(function (): Post {
            $category = Category::firstOrCreate(['code' => 'php'], ['title' => 'PHP']);

            return Post::create([
                'title' => 'Пост',
                'code' => 'post',
                'content' => 'content',
                'published' => true,
                'category_id' => $category->id,
            ]);
        });
    }
}

<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\PostView;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Чистка post_views — единственной таблицы блога, которая росла без ограничений
 * (telescope, упавшие задачи и токены сброса чистятся давно).
 *
 * Команда обязана трогать только записи роботов: живые просмотры — это счётчик
 * под статьёй и вся аналитика, их потеря видна читателю.
 */
class PrunePostViewsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_only_stale_bot_records(): void
    {
        $post = $this->createPost();

        // Записи делаются от даты, с которой роботы размечаются по User-Agent,
        // а «состариваются» переводом часов вперёд. Просто отнять 120 дней от
        // сегодня нельзя: такая запись окажется до ATTRIBUTION_SINCE, то есть
        // размеченной эвристикой, и прунинг её намеренно не тронет.
        $this->recordViewAt($post, isBot: true, at: $this->attributionStart());
        $this->recordViewAt($post, isBot: true, at: $this->attributionStart()->addDays(150));
        $this->recordViewAt($post, isBot: false, at: $this->attributionStart());

        $this->travelTo($this->attributionStart()->addDays(200));

        $this->artisan('post-views:prune')->assertSuccessful();

        $remaining = PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->get();

        // Остались свежий робот и человек; удалён только старый робот.
        $this->assertCount(2, $remaining);
        $this->assertSame(1, PostView::count());
    }

    public function test_dry_run_reports_the_count_without_deleting(): void
    {
        $post = $this->createPost();
        $this->recordViewAt($post, isBot: true, at: $this->attributionStart());

        $this->travelTo($this->attributionStart()->addDays(200));

        $this->artisan('post-views:prune --dry-run')
            ->expectsOutputToContain('Будет удалено 1')
            ->assertSuccessful();

        $this->assertSame(1, PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->count());
    }

    public function test_empty_table_is_reported_not_crashed(): void
    {
        $this->artisan('post-views:prune')
            ->expectsOutputToContain('нет')
            ->assertSuccessful();
    }

    /**
     * Опечатка в --days не должна означать «удалить почти всё».
     *
     * До явной проверки здесь стоял max(1, (int) $days): «quarter» приводился
     * к нулю, ноль поднимался до единицы, и команда сносила записи роботов за
     * всё время, кроме последних суток — молча и с кодом успеха.
     */
    public function test_non_numeric_retention_is_refused(): void
    {
        $post = $this->createPost();
        $this->recordViewAt($post, isBot: true, at: $this->attributionStart());
        $this->travelTo($this->attributionStart()->addDays(200));

        foreach (['quarter', '0', '-5', '3.5'] as $bad) {
            $this->artisan('post-views:prune --days='.$bad)->assertFailed();
        }

        $this->assertSame(1, PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->count());
    }

    /**
     * Записи, размеченные эвристикой «много сессий с одного IP», прунинг не
     * трогает: она может задеть офис за NAT, поэтому post-views:mark-bots
     * обещает обратимость через --reset. Удалив их, мы отняли бы у того
     * --reset предмет отката.
     */
    public function test_records_marked_by_heuristic_survive(): void
    {
        $post = $this->createPost();

        PostView::create([
            'post_id' => $post->id,
            'session_hash' => hash('sha256', 'historical'),
            'is_bot' => true,
            // До ATTRIBUTION_SINCE User-Agent не сохранялся, значит бит
            // проставлен косвенно.
            'viewed_at' => Carbon::parse(PostView::ATTRIBUTION_SINCE)->subDays(5),
        ]);

        $this->artisan('post-views:prune --days=1')->assertSuccessful();

        $this->assertSame(1, PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->count());
    }

    public function test_retention_window_is_configurable(): void
    {
        $post = $this->createPost();
        $this->recordViewAt($post, isBot: true, at: $this->attributionStart());

        $this->travelTo($this->attributionStart()->addDays(30));

        $this->artisan('post-views:prune --days=200')->assertSuccessful();
        $this->assertSame(1, PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->count());

        $this->artisan('post-views:prune --days=7')->assertSuccessful();
        $this->assertSame(0, PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->count());
    }

    /**
     * Страж расписания: инцидент был именно про него.
     *
     * queue:prune-batches падала каждое воскресенье, потому что чистила
     * таблицу несуществующих батчей, а чистки post_views не было вовсе — при
     * том что telescope, упавшие задачи и токены сброса убираются давно.
     * Тестов на расписание в проекте не было ни одного, так что обе половины
     * инцидента ничем не фиксировались.
     */
    public function test_schedule_prunes_views_and_no_longer_prunes_batches(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command);

        $this->assertTrue(
            $commands->contains(fn (string $c): bool => str_contains($c, 'post-views:prune')),
            'чистка post_views выпала из расписания — таблица снова растёт без ограничений',
        );

        $this->assertFalse(
            $commands->contains(fn (string $c): bool => str_contains($c, 'queue:prune-batches')),
            'queue:prune-batches вернулась в расписание, а таблицы job_batches нет — планировщик снова будет падать',
        );
    }

    /** Дата, с которой роботы размечаются по User-Agent, а не эвристикой. */
    private function attributionStart(): Carbon
    {
        return Carbon::parse(PostView::ATTRIBUTION_SINCE)->startOfDay();
    }

    private function recordViewAt(Post $post, bool $isBot, Carbon $at): void
    {
        PostView::create([
            'post_id' => $post->id,
            'session_hash' => hash('sha256', $post->id.'-'.(int) $isBot.'-'.$at->timestamp),
            'is_bot' => $isBot,
            'viewed_at' => $at,
        ]);
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

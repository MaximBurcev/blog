<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\PostView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Разметка исторических просмотров роботами.
 *
 * Команда правит уже собранные данные, поэтому её границы важнее её эвристики:
 * записи с User-Agent-детектом она трогать не смеет ни в одну сторону.
 */
class MarkBotViewsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_addresses_with_many_sessions(): void
    {
        $post = $this->createPost();
        $this->history($post, ip: 'crawler', sessions: 9);
        $this->history($post, ip: 'reader', sessions: 2);

        $this->artisan('post-views:mark-bots')->assertSuccessful();

        $this->assertSame(9, $this->botCount());
        $this->assertSame(2, PostView::count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $post = $this->createPost();
        $this->history($post, ip: 'crawler', sessions: 9);

        $this->artisan('post-views:mark-bots --dry-run')->assertSuccessful();

        $this->assertSame(0, $this->botCount());
    }

    public function test_views_recorded_after_user_agent_detection_are_never_touched(): void
    {
        $post = $this->createPost();

        // Офис или мобильный оператор за NAT: с одного адреса четыре живых
        // читателя, уже правильно распознанных по User-Agent. Без верхней
        // границы по времени эвристика объявила бы роботами всех четверых, и
        // чем позже запускать команду, тем больше людей она бы съедала.
        $this->history($post, ip: 'office-nat', sessions: 6, at: now());

        $this->artisan('post-views:mark-bots')->assertSuccessful();

        $this->assertSame(0, $this->botCount());
        $this->assertSame(6, PostView::count());
    }

    public function test_reset_restores_only_historical_records(): void
    {
        $post = $this->createPost();
        $this->history($post, ip: 'crawler', sessions: 5);
        // Помечен детектором по User-Agent уже после внедрения.
        $this->history($post, ip: 'googlebot', sessions: 1, at: now(), isBot: true);

        $this->artisan('post-views:mark-bots')->assertSuccessful();
        $this->assertSame(6, $this->botCount());

        $this->artisan('post-views:mark-bots --reset')->assertSuccessful();

        // Разметка детектора пережила сброс: восстановить её нечем — сам
        // User-Agent намеренно не хранится.
        $this->assertSame(1, $this->botCount());
    }

    public function test_reset_respects_dry_run(): void
    {
        $post = $this->createPost();
        $this->history($post, ip: 'crawler', sessions: 5);
        $this->artisan('post-views:mark-bots')->assertSuccessful();

        $this->artisan('post-views:mark-bots --reset --dry-run')->assertSuccessful();

        // Раньше --reset выполнялся первым и писал, игнорируя просьбу показать.
        $this->assertSame(5, $this->botCount());
    }

    public function test_threshold_below_two_is_refused(): void
    {
        $this->artisan('post-views:mark-bots --sessions=1')->assertFailed();
    }

    private function botCount(): int
    {
        return PostView::withoutGlobalScope(PostView::HUMANS_ONLY)->where('is_bot', true)->count();
    }

    private function history(Post $post, string $ip, int $sessions, ?Carbon $at = null, bool $isBot = false): void
    {
        $viewedAt = $at ?? Carbon::parse(PostView::ATTRIBUTION_SINCE)->subDays(3);

        for ($i = 0; $i < $sessions; $i++) {
            PostView::create([
                'post_id' => $post->id,
                'ip_hash' => hash('sha256', $ip),
                'session_hash' => hash('sha256', $ip.'-'.$i.'-'.$viewedAt->timestamp),
                'is_bot' => $isBot,
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

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Analytics\Widgets\PublishingPace;
use App\Filament\Analytics\Widgets\ReadingDepth;
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
 * Два виджета, отвечающие на вопросы, которые до сих пор решались наугад:
 * доходит ли читатель до второй статьи и успеваем ли мы публиковать то, что
 * приносит парсер.
 */
class AnalyticsInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_depth_counts_readers_not_views(): void
    {
        $post = $this->createPost('a', published: true);
        $other = $this->createPost('b', published: true);

        // Один читатель осилил две статьи, двое — по одной.
        $this->recordView($post, 'reader-1');
        $this->recordView($other, 'reader-1');
        $this->recordView($post, 'reader-2');
        $this->recordView($post, 'reader-3');

        $stats = $this->statsOf(ReadingDepth::class);

        $this->assertSame('67%', $stats['Читают только одну статью']);
        $this->assertSame('33%', $stats['Идут дальше первой']);
        $this->assertSame('2', $stats['Самый глубокий читатель']);
    }

    /**
     * Повторный заход на ту же статью — не глубина.
     *
     * Дедуп в PostViewService держится 24 часа, а окно виджета до 90 дней:
     * читатель, вернувшийся к той же статье через неделю, создаёт вторую
     * запись. По COUNT(*) он попадал бы в «идут дальше первой» и завышал
     * ровно ту долю, которую виджет измеряет.
     */
    public function test_returning_to_the_same_article_is_not_depth(): void
    {
        $post = $this->createPost('a', published: true);

        $this->recordView($post, 'reader-1');
        $this->recordView($post, 'reader-1', daysAgo: 3);

        $stats = $this->statsOf(ReadingDepth::class);

        $this->assertSame('100%', $stats['Читают только одну статью']);
        $this->assertSame('0%', $stats['Идут дальше первой']);
        $this->assertSame('1', $stats['Самый глубокий читатель']);
    }

    public function test_reading_depth_ignores_crawlers(): void
    {
        $post = $this->createPost('a', published: true);
        $other = $this->createPost('b', published: true);

        $this->recordView($post, 'human');
        // Робот, обошедший обе статьи, не должен изображать глубокое чтение.
        $this->recordView($post, 'crawler', isBot: true);
        $this->recordView($other, 'crawler', isBot: true);

        $stats = $this->statsOf(ReadingDepth::class);

        $this->assertSame('100%', $stats['Читают только одну статью']);
        $this->assertSame('0%', $stats['Идут дальше первой']);
    }

    public function test_reading_depth_survives_empty_period(): void
    {
        $stats = $this->statsOf(ReadingDepth::class);

        $this->assertArrayHasKey('Читателей за неделю', $stats);
        $this->assertSame('0', $stats['Читателей за неделю']);
    }

    public function test_publishing_pace_separates_queue_from_flow(): void
    {
        // Разобрано за период три, опубликован один; ещё один — заглушка.
        $this->createPost('published-now', published: true);
        $this->createPost('draft-1', published: false);
        $this->createPost('draft-2', published: false);
        $this->createPost('broken', published: false, parseStatus: Post::PARSE_STATUS_FAILED);

        // Статья, вышедшая у источника полгода назад, но разобранная сейчас,
        // обязана попасть в текущий период: считаем момент разбора, а не дату
        // оригинала.
        $old = $this->createPost('old-original', published: false);
        $old->forceFill(['created_at' => Carbon::now()->subMonths(6)])->save();

        // Пост без parse_status: так выглядит всё, заведённое до 30.07.2026 и
        // всё, что создают руками в админке. Из очереди он выпадать не должен.
        $this->createPost('legacy-draft', published: false, parseStatus: null);

        $stats = $this->statsOf(PublishingPace::class);

        $this->assertSame('1', $stats['Опубликовано за неделю']);
        $this->assertSame('6', $stats['Разобрано парсером']);
        // Очередь — это состояние, а не поток: заглушка в неё не входит,
        // публиковать её нечем.
        $this->assertSame('4', $stats['Ждут публикации']);
        $this->assertSame('1', $stats['С ошибкой парсинга']);
    }

    /**
     * Дата публикации проставляется сама и только один раз.
     *
     * Снять галочку можно из формы поста, из массового действия и из tinker —
     * держать это в контроллерах значило бы забыть в одном из мест. А повторная
     * публикация не должна выглядеть новой, иначе темп накрутится правкой
     * старых постов.
     */
    public function test_published_at_is_set_once_on_first_publication(): void
    {
        $post = $this->createPost('draft', published: false);

        $this->assertNull($post->published_at);

        $post->update(['published' => true]);
        $first = $post->fresh()->published_at;

        $this->assertNotNull($first);

        // Сняли с публикации и вернули — дата прежняя.
        $post->update(['published' => false]);
        $this->travel(2)->days();
        $post->update(['published' => true]);

        $this->assertTrue($first->equalTo($post->fresh()->published_at));
    }

    /**
     * @return array<string, string>
     */
    private function statsOf(string $widget): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $instance = Livewire::actingAs($admin)
            ->test($widget, ['filters' => ['period' => 7]])
            ->assertSuccessful()
            ->instance();

        $stats = [];

        foreach ((new ReflectionMethod($instance, 'getStats'))->invoke($instance) as $stat) {
            /** @var Stat $stat */
            $stats[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        return $stats;
    }

    private function recordView(Post $post, string $visitor, bool $isBot = false, int $daysAgo = 0): void
    {
        PostView::create([
            'post_id' => $post->id,
            'session_hash' => hash('sha256', $visitor),
            'is_bot' => $isBot,
            'viewed_at' => Carbon::now()->subDays($daysAgo)->subHours(2),
        ]);
    }

    private function createPost(string $code, bool $published, ?string $parseStatus = Post::PARSE_STATUS_OK): Post
    {
        return Post::withoutSyncingToSearch(function () use ($code, $published, $parseStatus): Post {
            $category = Category::firstOrCreate(['code' => 'php'], ['title' => 'PHP']);

            return Post::create([
                'title' => 'Пост '.$code,
                'code' => $code,
                'content' => 'content',
                'published' => $published,
                'parse_status' => $parseStatus,
                // Момент разбора парсером. created_at для этого не годится: там
                // лежит дата публикации оригинала у источника, и она бывает
                // старше на месяцы.
                'parsed_at' => Carbon::now()->subHours(3),
                'category_id' => $category->id,
            ]);
        });
    }
}

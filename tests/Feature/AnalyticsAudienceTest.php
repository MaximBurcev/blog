<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Analytics\Widgets\CommentsAndLikes;
use App\Filament\Analytics\Widgets\NewsArticlesSplit;
use App\Filament\Analytics\Widgets\ReaderRetention;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Виджеты волны «внешняя видимость»: возвраты читателей, комментарии/лайки
 * и разрез новости/статьи. Приёмы те же, что в AnalyticsPageTest: значения
 * берутся из самих плиток, а не из HTML разметки.
 */
class AnalyticsAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_counts_readers_returning_on_another_day(): void
    {
        $post = $this->createPost('Пост с читателями', 'post-with-readers');

        // Читатель А: визиты в два разных дня — вернулся.
        $this->recordView($post, 'reader-a', daysAgo: 2);
        $this->recordView($post, 'reader-a', daysAgo: 1);
        // Читатель Б: два просмотра в один день — не вернулся.
        $this->recordView($post, 'reader-b', daysAgo: 1);
        $this->recordView($post, 'reader-b', daysAgo: 1);

        $stats = $this->statsFor(ReaderRetention::class, ['period' => 7]);

        $this->assertSame('1', $stats['Вернулись на другой день']);
        $this->assertSame('50%', $stats['Доля вернувшихся']);
        $this->assertStringContainsString(
            'Из 2 читателей',
            $this->statDescriptionsFor(ReaderRetention::class, ['period' => 7])['Вернулись на другой день'],
        );
    }

    public function test_retention_falls_back_to_ip_hash_and_ignores_bots(): void
    {
        $post = $this->createPost('Пост с разными хэшами', 'post-with-hashes');

        // Без сессии читатель опознаётся по ip_hash — см. COALESCE в
        // PostViewsOverview, подход тот же.
        $this->recordView($post, null, ipHash: 'ip-reader', daysAgo: 3);
        $this->recordView($post, null, ipHash: 'ip-reader', daysAgo: 1);
        // Робот с визитами в два дня читателем не становится.
        $this->recordView($post, 'bot-session', daysAgo: 2, isBot: true);
        $this->recordView($post, 'bot-session', daysAgo: 1, isBot: true);
        // Просмотр без обоих хэшей — не читатель (иначе один такой посетитель
        // считался бы за отдельного).
        $this->recordView($post, null, daysAgo: 1);

        $stats = $this->statsFor(ReaderRetention::class, ['period' => 7]);

        $this->assertSame('1', $stats['Вернулись на другой день']);
        $this->assertSame('100%', $stats['Доля вернувшихся']);
    }

    public function test_retention_survives_a_period_without_readers(): void
    {
        $stats = $this->statsFor(ReaderRetention::class, ['period' => 7]);

        // «Некого считать» и «0%» — разные утверждения, поэтому прочерк.
        $this->assertSame('0', $stats['Вернулись на другой день']);
        $this->assertSame('—', $stats['Доля вернувшихся']);
    }

    public function test_comments_and_likes_widget_counts_engagement(): void
    {
        $post = $this->createPost('Пост с реакциями', 'post-with-reactions');
        $user = User::factory()->create();

        // Два опубликованных комментария: один свежий, один месячной давности.
        $this->createComment($post, $user, published: true, daysAgo: 30);
        $this->createComment($post, $user, published: true);
        // Один ждёт модерации.
        $this->createComment($post, $user, published: false);

        PostLike::create(['post_id' => $post->id, 'user_id' => $user->id]);
        // Второй лайк месячной давности — в недельный счётчик попасть не должен.
        // Уникальный индекс post_likes не пустит два лайка одного пользователя,
        // поэтому второй автор.
        PostLike::create(['post_id' => $post->id, 'user_id' => User::factory()->create()->id])
            ->forceFill(['created_at' => now()->subDays(30)])->save();

        $stats = $this->statsFor(CommentsAndLikes::class, []);
        $descriptions = $this->statDescriptionsFor(CommentsAndLikes::class, []);

        $this->assertSame('3', $stats['Комментариев всего']);
        $this->assertSame('На модерации: 1', $descriptions['Комментариев всего']);
        // Два свежих: опубликованный и ждущий модерации — месячной давности
        // в недельный счётчик не попадает.
        $this->assertSame('2', $stats['Комментариев за 7 дней']);
        $this->assertSame('2', $stats['Лайков всего']);
        $this->assertSame('За 7 дней: 1', $descriptions['Лайков всего']);
    }

    public function test_news_articles_split_counts_both_feeds(): void
    {
        // Свежие и старые записи обеих лент; черновик не считается нигде.
        $this->createPost('Свежая статья', 'fresh-article');
        $this->createPost('Старая статья', 'old-article', publishedDaysAgo: 60);
        $this->createPost('Свежая новость', 'fresh-news', isNews: true);
        $this->createPost('Черновик новости', 'draft-news', isNews: true, published: false);

        $stats = $this->statsFor(NewsArticlesSplit::class, ['period' => 30]);
        $descriptions = $this->statDescriptionsFor(NewsArticlesSplit::class, ['period' => 30]);

        $this->assertSame('2', $stats['Статей опубликовано']);
        $this->assertSame('За месяц: 1', $descriptions['Статей опубликовано']);
        $this->assertSame('1', $stats['Новостей опубликовано']);
        $this->assertSame('За месяц: 1', $descriptions['Новостей опубликовано']);
    }

    /**
     * Значения плиток, а не подстроки в HTML — тот же приём, что в
     * AnalyticsPageTest::mapStats.
     *
     * @param  class-string  $widgetClass
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function statsFor(string $widgetClass, array $filters): array
    {
        return $this->mapWidgetStats($widgetClass, $filters, fn ($stat) => (string) $stat->getValue());
    }

    /**
     * @param  class-string  $widgetClass
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function statDescriptionsFor(string $widgetClass, array $filters): array
    {
        return $this->mapWidgetStats($widgetClass, $filters, fn ($stat) => (string) $stat->getDescription());
    }

    /**
     * @param  class-string  $widgetClass
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function mapWidgetStats(string $widgetClass, array $filters, callable $extract): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $widget = Livewire::actingAs($admin)
            ->test($widgetClass, $filters === [] ? [] : ['filters' => $filters])
            ->assertSuccessful()
            ->instance();

        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        $map = [];

        foreach ($method->invoke($widget) as $stat) {
            $map[(string) $stat->getLabel()] = $extract($stat);
        }

        return $map;
    }

    private function createPost(
        string $title,
        string $code,
        bool $isNews = false,
        bool $published = true,
        int $publishedDaysAgo = 0,
    ): Post {
        return Post::withoutSyncingToSearch(function () use ($title, $code, $isNews, $published, $publishedDaysAgo): Post {
            $category = Category::firstOrCreate(
                ['code' => 'php'],
                ['title' => 'PHP'],
            );

            $post = Post::create([
                'title' => $title,
                'code' => $code,
                'content' => 'content',
                'published' => $published,
                'is_news' => $isNews,
                'category_id' => $category->id,
            ]);

            // published_at не fillable и проставляется моделью в момент
            // публикации — «старому» посту дату переписываем вручную.
            if ($publishedDaysAgo > 0) {
                $post->forceFill(['published_at' => now()->subDays($publishedDaysAgo)])->save();
            }

            return $post;
        });
    }

    private function recordView(
        Post $post,
        ?string $sessionHash,
        ?string $ipHash = null,
        int $daysAgo = 0,
        bool $isBot = false,
    ): void {
        // Начало суток — тот же приём, что в AnalyticsPageTest::recordViews:
        // просмотр должен попадать в своё окно, в котором бы часу ни гонялись
        // тесты.
        PostView::create([
            'post_id' => $post->id,
            'session_hash' => $sessionHash,
            'ip_hash' => $ipHash,
            'is_bot' => $isBot,
            'viewed_at' => now()->subDays($daysAgo)->setTime(0, 0),
        ]);
    }

    private function createComment(Post $post, User $user, bool $published, int $daysAgo = 0): void
    {
        $comment = Comment::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'message' => 'Комментарий',
            'published' => $published,
        ]);

        if ($daysAgo > 0) {
            $comment->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }
    }
}

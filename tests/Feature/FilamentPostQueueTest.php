<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PostResource\Pages\EditPost;
use App\Filament\Resources\PostResource\Pages\ListPosts;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Редакторская очередь в админке (волна A): поиск по заголовку, фильтр
 * «требует ревью перевода», массовая публикация/снятие и тоггл published
 * прямо в списке — всё то, что раньше требовало открытия формы поста.
 */
class FilamentPostQueueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    /**
     * Не post(): в Laravel 13 у Illuminate\Foundation\Testing\TestCase есть
     * публичный post() для HTTP-запросов, и одноимённый приватный метод
     * роняет класс на fatal.
     */
    private function makePost(array $attributes = []): Post
    {
        static $n = 0;
        $n++;

        return Post::create(array_merge([
            'title' => 'Заголовок '.$n,
            'content' => '<p>Текст записи '.$n.'.</p>',
            'code' => 'zapis-'.$n,
            'published' => false,
            'is_news' => false,
        ], $attributes));
    }

    public function test_review_tab_shows_only_ready_drafts(): void
    {
        Post::withoutSyncingToSearch(function () {
            $ready = $this->makePost([
                'parse_status' => Post::PARSE_STATUS_OK,
                'translation_incomplete' => false,
            ]);
            $needsTranslationReview = $this->makePost([
                'parse_status' => Post::PARSE_STATUS_OK,
                'translation_incomplete' => true,
            ]);
            $published = $this->makePost([
                'published' => true,
                'parse_status' => Post::PARSE_STATUS_OK,
            ]);
            $parseFailed = $this->makePost(['parse_status' => Post::PARSE_STATUS_FAILED]);

            Livewire::actingAs($this->admin())
                ->test(ListPosts::class)
                ->set('activeTab', 'review')
                ->assertCanSeeTableRecords([$ready])
                ->assertCanNotSeeTableRecords([$needsTranslationReview, $published, $parseFailed]);
        });
    }

    public function test_translation_tab_shows_flagged_posts(): void
    {
        Post::withoutSyncingToSearch(function () {
            $flagged = $this->makePost(['translation_incomplete' => true]);
            $fine = $this->makePost(['translation_incomplete' => false]);

            Livewire::actingAs($this->admin())
                ->test(ListPosts::class)
                ->set('activeTab', 'translation')
                ->assertCanSeeTableRecords([$flagged])
                ->assertCanNotSeeTableRecords([$fine]);
        });
    }

    public function test_title_search_finds_post(): void
    {
        Post::withoutSyncingToSearch(function () {
            $post = $this->makePost(['title' => 'Уникальный заголовок для поиска']);
            $this->makePost();

            Livewire::actingAs($this->admin())
                ->test(ListPosts::class)
                ->searchTable('Уникальный заголовок')
                ->assertCanSeeTableRecords([$post]);
        });
    }

    public function test_translation_review_filter(): void
    {
        Post::withoutSyncingToSearch(function () {
            $needsReview = $this->makePost(['translation_incomplete' => true]);
            $fine = $this->makePost(['translation_incomplete' => false]);

            Livewire::actingAs($this->admin())
                ->test(ListPosts::class)
                ->filterTable('translation_incomplete', true)
                ->assertCanSeeTableRecords([$needsReview])
                ->assertCanNotSeeTableRecords([$fine]);
        });
    }

    public function test_bulk_publish_and_unpublish(): void
    {
        Post::withoutSyncingToSearch(function () {
            $drafts = collect([$this->makePost(), $this->makePost()]);

            Livewire::actingAs($this->admin())
                ->test(ListPosts::class)
                ->callTableBulkAction('publish', $drafts);

            foreach ($drafts as $draft) {
                $this->assertTrue($draft->fresh()->published);
                // Дату первой публикации ставит saving-хук модели — массовый
                // экшен обязан идти через него, а не мимо.
                $this->assertNotNull($draft->fresh()->published_at);
            }

            Livewire::actingAs($this->admin())
                ->test(ListPosts::class)
                ->callTableBulkAction('unpublish', $drafts);

            foreach ($drafts as $draft) {
                $this->assertFalse($draft->fresh()->published);
            }
        });
    }

    public function test_published_toggle_column(): void
    {
        Post::withoutSyncingToSearch(function () {
            $post = $this->makePost();

            Livewire::actingAs($this->admin())
                ->test(ListPosts::class)
                // Так же кликает браузер: ToggleColumn — Editable-колонка, и её
                // состояние меняет updateTableColumnState, а не экшен колонки.
                ->call('updateTableColumnState', 'published', (string) $post->getKey(), true);

            $this->assertTrue($post->fresh()->published);
        });
    }

    public function test_edit_page_shows_translation_engine(): void
    {
        Post::withoutSyncingToSearch(function () {
            $llm = $this->makePost(['translated_by' => 'gemini-3.5-flash']);
            $scraper = $this->makePost(['translated_by' => 'google']);

            Livewire::actingAs($this->admin())
                ->test(EditPost::class, ['record' => $llm->getKey()])
                ->assertSee('LLM (gemini-3.5-flash)');

            Livewire::actingAs($this->admin())
                ->test(EditPost::class, ['record' => $scraper->getKey()])
                ->assertSee('Скрейпер (запасной движок, стоит перевести заново)');
        });
    }

    public function test_view_on_site_action_visible_only_for_published(): void
    {
        Post::withoutSyncingToSearch(function () {
            $draft = $this->makePost();
            $published = $this->makePost(['published' => true]);
            $publishedNews = $this->makePost(['published' => true, 'is_news' => true]);

            Livewire::actingAs($this->admin())
                ->test(ListPosts::class)
                ->assertTableActionHidden('viewOnSite', $draft)
                ->assertTableActionVisible('viewOnSite', $published)
                // У новости свой адрес — /news/{code}, не адрес статьи.
                ->assertTableActionHasUrl('viewOnSite', route('news.show', $publishedNews->code), $publishedNews);
        });
    }
}

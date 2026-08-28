<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Service\Translation\GoogleScraperTranslator;
use App\Service\Translation\TranslationResult;
use App\Service\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Пакетный перевод черновиков (волна A): расписание гоняет
 * posts:translate-drafts партиями, пока завал не кончится.
 */
class TranslateDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Переводчик-подмена: возвращает осмысленный русский текст, чтобы пройти
     * автооценку качества (доля кириллицы, длина, концовка).
     */
    private function fakeTranslator(string $engine = 'gemini-test'): Translator
    {
        return new class($engine) implements Translator
        {
            public function __construct(private readonly string $engine) {}

            public function translateHtml(string $html): TranslationResult
            {
                return TranslationResult::success('<p>Переведённый текст статьи, достаточно длинный для проверок качества.</p>', $this->engine);
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::success('Переведённый заголовок', $this->engine);
            }

            public function name(): string
            {
                return $this->engine;
            }
        };
    }

    private function draft(array $attributes = []): Post
    {
        static $n = 0;
        $n++;

        return Post::create(array_merge([
            'title' => 'English title '.$n,
            'content' => '<p>English content '.$n.'.</p>',
            'content_orig' => '<p>English content '.$n.' long enough to be translated properly.</p>',
            'code' => 'draft-'.$n,
            'published' => false,
            'is_news' => false,
            'parse_status' => Post::PARSE_STATUS_OK,
        ], $attributes));
    }

    public function test_translates_drafts_and_leaves_others_alone(): void
    {
        Post::withoutSyncingToSearch(function () {
            $first = $this->draft();
            $second = $this->draft();
            $published = $this->draft(['published' => true, 'code' => 'published-post']);
            $failed = $this->draft(['parse_status' => Post::PARSE_STATUS_FAILED, 'code' => 'failed-post']);

            $this->app->instance(Translator::class, $this->fakeTranslator());

            $this->artisan('posts:translate-drafts')->assertSuccessful();

            $first->refresh();
            $second->refresh();

            $this->assertSame('Переведённый заголовок', $first->title);
            $this->assertStringContainsString('Переведённый текст', $first->content);
            $this->assertSame('gemini-test', $first->translated_by);
            $this->assertFalse((bool) $first->translation_incomplete);
            // Перевод не публикует: решение о публикации — за вычиткой в админке.
            $this->assertFalse((bool) $first->published);
            $this->assertSame('gemini-test', $second->translated_by);

            // Опубликованные и неразобранные посты команда не трогает.
            $this->assertSame('English title 3', $published->refresh()->title);
            $this->assertSame('English title 4', $failed->refresh()->title);
        });
    }

    public function test_respects_limit(): void
    {
        Post::withoutSyncingToSearch(function () {
            $this->draft();
            $this->draft();
            $this->draft();

            $this->app->instance(Translator::class, $this->fakeTranslator());

            $this->artisan('posts:translate-drafts', ['--limit' => 2])->assertSuccessful();

            $this->assertSame(2, Post::where('translated_by', 'gemini-test')->count());
        });
    }

    public function test_stops_batch_on_fallback_engine(): void
    {
        Post::withoutSyncingToSearch(function () {
            $this->draft();
            $this->draft();

            $this->app->instance(Translator::class, $this->fakeTranslator(GoogleScraperTranslator::NAME));

            $this->artisan('posts:translate-drafts')
                ->expectsOutputToContain('Партию останавливаю')
                ->assertSuccessful();

            // Первый пост переведён (скрейпером — пометка честная), второй
            // ждёт следующего запуска: завал скрейпером мы уже один раз
            // переводили, второй раз тем же движком — бессмысленно.
            $this->assertSame(1, Post::where('translated_by', GoogleScraperTranslator::NAME)->count());
            $this->assertSame(1, Post::whereNull('translated_by')->count());
        });
    }

    public function test_dry_run_changes_nothing(): void
    {
        Post::withoutSyncingToSearch(function () {
            $this->draft();

            $this->app->instance(Translator::class, $this->fakeTranslator());

            $this->artisan('posts:translate-drafts', ['--dry-run' => true])
                ->expectsOutputToContain('Пробный прогон')
                ->assertSuccessful();

            $this->assertNull(Post::first()->translated_by);
        });
    }
}

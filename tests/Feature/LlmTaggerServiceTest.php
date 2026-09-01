<?php

namespace Tests\Feature;

use App\DataTransferObjects\PostData;
use App\Models\Category;
use App\Models\LlmCall;
use App\Models\Post;
use App\Models\Tag;
use App\Service\LlmTaggerService;
use App\Service\PostService;
use App\Service\Translation\FallbackTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Тегирование через LLM — запасной путь к словарю.
 *
 * Как и с переводом, тесты здесь про недоверие: мусорный ответ, лёгкая
 * модель и разомкнутый предохранитель обязаны стоить посту только тегов,
 * а не самого поста.
 */
class LlmTaggerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // В phpunit.xml фича выключена (см. комментарий там), тесты
            // тегирования включают её обратно явно.
            'tagging.llm_enabled' => true,
            'translation.gemini.key' => 'test-key',
            'translation.gemini.model' => 'gemini-3.6-flash',
            // Без задержек: иначе прогон упирается в реальные паузы повторов.
            'translation.gemini.retry_delays_ms' => [0],
            // Размыкатель включается только в тесте про него; в остальных он
            // гасил бы движок после первого же смоделированного отказа.
            'translation.circuit_breaker_seconds' => 0,
        ]);
    }

    public function test_existing_tag_is_reused_and_new_one_created(): void
    {
        $existing = Tag::create(['title' => 'Shipping', 'code' => 'shipping']);

        $this->fakeAnswer('["Shipping", "USPS"]');

        $ids = $this->tagger()->detect('Integrating a US store with USPS labels', 'https://example.test/x', '<p>Postage and labels.</p>');

        $this->assertCount(2, $ids);
        $this->assertContains($existing->id, $ids, 'существующий тег не должен дублироваться');

        $new = Tag::where('title', 'USPS')->first();
        $this->assertNotNull($new);
        $this->assertSame('usps', $new->code);
        $this->assertContains($new->id, $ids);

        $this->assertDatabaseHas('llm_calls', ['kind' => LlmCall::KIND_TAGS, 'outcome' => LlmCall::OUTCOME_OK]);
    }

    public function test_markdown_wrapped_json_is_accepted(): void
    {
        // Модель оборачивает ответ в ```json, даже когда просили не делать
        // этого, — тот же режим, что и у перевода.
        $this->fakeAnswer("```json\n[\"USPS\"]\n```");

        $ids = $this->tagger()->detect('USPS labels');

        $this->assertCount(1, $ids);
        $this->assertNotNull(Tag::where('title', 'USPS')->first());
    }

    public function test_garbage_answer_returns_nothing_and_marks_call_rejected(): void
    {
        $this->fakeAnswer('Извините, я не могу подобрать теги для этой статьи.');

        $ids = $this->tagger()->detect('USPS labels');

        $this->assertSame([], $ids);
        $this->assertSame(0, Tag::count(), 'мусорный ответ не должен создавать теги');
        // Токены потрачены, пользы нет: вызов перекрашен, а не удалён —
        // доля такого брака видна в журнале расходов.
        $this->assertDatabaseHas('llm_calls', ['kind' => LlmCall::KIND_TAGS, 'outcome' => LlmCall::OUTCOME_REJECTED]);
    }

    public function test_too_many_tags_or_too_long_tag_is_garbage(): void
    {
        // Валидация на всё или ничего: четыре хороших тега рядом с седьмым
        // сочинённым — признак того, что модель не поняла формат.
        $this->fakeAnswer('["One", "Two", "Three", "Four", "Five", "Six"]');

        $this->assertSame([], $this->tagger()->detect('USPS labels'));

        $this->fakeAnswer('["'.str_repeat('Long', 20).'"]');

        $this->assertSame([], $this->tagger()->detect('USPS labels'));
        $this->assertSame(0, Tag::count());
    }

    public function test_api_error_returns_nothing_and_does_not_throw(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->assertSame([], $this->tagger()->detect('USPS labels'));
        $this->assertDatabaseHas('llm_calls', ['kind' => LlmCall::KIND_TAGS, 'outcome' => LlmCall::OUTCOME_ERROR]);
    }

    public function test_open_circuit_breaker_skips_the_network(): void
    {
        // Модель уже ответила неретраибельной ошибкой — теги не перевод,
        // выжидать таймаут на каждом посте ради них незачем.
        config(['translation.circuit_breaker_seconds' => 300]);
        FallbackTranslator::markDown('gemini-3.6-flash');

        Http::fake();

        $this->assertSame([], $this->tagger()->detect('USPS labels'));
        Http::assertNothingSent();
        $this->assertSame(0, LlmCall::where('kind', LlmCall::KIND_TAGS)->count());
    }

    public function test_disabled_feature_returns_nothing_without_network(): void
    {
        config(['tagging.llm_enabled' => false]);
        Http::fake();

        $this->assertSame([], $this->tagger()->detect('USPS labels'));
        Http::assertNothingSent();
    }

    public function test_post_store_falls_back_to_llm_when_dictionary_misses(): void
    {
        $category = Category::create(['title' => 'Переводы', 'code' => 'translations']);
        Tag::create(['title' => 'Shipping', 'code' => 'shipping']);

        $this->fakeAnswer('["Shipping", "USPS"]');

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Integrating a US store with USPS labels',
            'content' => '<p>How to wire checkout to postage rates and print labels.</p>',
            'category_id' => $category->id,
            'url' => 'https://example.test/usps-labels',
        ])));

        $titles = $post->tags()->pluck('title')->sort()->values()->all();

        $this->assertSame(['Shipping', 'USPS'], $titles);
    }

    public function test_llm_is_not_called_when_dictionary_matches(): void
    {
        $category = Category::create(['title' => 'Переводы', 'code' => 'translations']);
        $laravel = Tag::create(['title' => 'Laravel', 'code' => 'laravel']);

        Http::fake();

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Laravel queues in practice',
            'content' => '<p>content</p>',
            'category_id' => $category->id,
            'url' => 'https://example.test/laravel-queues',
        ])));

        $this->assertSame([$laravel->id], $post->tags()->pluck('tags.id')->all());
        Http::assertNothingSent();
        $this->assertSame(0, LlmCall::where('kind', LlmCall::KIND_TAGS)->count());
    }

    public function test_post_is_saved_even_when_llm_fails(): void
    {
        $category = Category::create(['title' => 'Переводы', 'code' => 'translations']);

        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Integrating a US store with USPS labels',
            'content' => '<p>content</p>',
            'category_id' => $category->id,
            'url' => 'https://example.test/usps-fail',
        ])));

        $this->assertTrue($post->exists, 'сбой тегирования не должен стоить поста');
        $this->assertSame([], $post->tags()->pluck('tags.id')->all());
    }

    private function tagger(): LlmTaggerService
    {
        return app(LlmTaggerService::class);
    }

    private function fakeAnswer(string $text): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => $text]]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);
    }
}

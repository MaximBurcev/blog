<?php

namespace Tests\Feature;

use App\DataTransferObjects\PostData;
use App\Models\Category;
use App\Models\LlmCall;
use App\Models\Post;
use App\Service\LlmCategoryService;
use App\Service\PostService;
use App\Service\Translation\FallbackTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Выбор категории через LLM — запасной путь к словарю.
 *
 * Как и с тегами, тесты здесь про недоверие: мусорный ответ, лёгкая модель
 * и разомкнутый предохранитель обязаны стоить посту только категории, а не
 * самого поста.
 */
class LlmCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // В phpunit.xml фича выключена (см. комментарий там), тесты
            // включают её обратно явно.
            'tagging.llm_category_enabled' => true,
            'translation.gemini.key' => 'test-key',
            'translation.gemini.model' => 'gemini-3.6-flash',
            // Цепочка из двух моделей фиксирована: иначе список брался бы из
            // .env разработчика, и прогон зависел бы от машины.
            'translation.gemini.fallback_models' => ['gemini-3.5-flash'],
            // Без задержек: иначе прогон упирается в реальные паузы повторов.
            'translation.gemini.retry_delays_ms' => [0],
            // Размыкатель включается только в тестах про него; в остальных он
            // гасил бы движок после первого же смоделированного отказа.
            'translation.circuit_breaker_seconds' => 0,
        ]);
    }

    public function test_existing_category_is_chosen_not_duplicated(): void
    {
        $existing = Category::create(['title' => 'Shipping', 'code' => 'shipping']);

        $this->fakeAnswer('{"category": "Shipping"}');

        $id = $this->service()->detect('Integrating a US store with USPS labels', 'https://example.test/x', '<p>Postage and labels.</p>');

        $this->assertSame($existing->id, $id);
        $this->assertSame(1, Category::count(), 'существующая категория не должна дублироваться');
        $this->assertDatabaseHas('llm_calls', ['kind' => LlmCall::KIND_CATEGORY, 'outcome' => LlmCall::OUTCOME_OK]);
    }

    public function test_new_category_is_created_when_none_fits(): void
    {
        Category::create(['title' => 'Shipping', 'code' => 'shipping']);

        $this->fakeAnswer('{"category": "E-commerce"}');

        $id = $this->service()->detect('Integrating a US store with USPS labels');

        $category = Category::find($id);
        $this->assertNotNull($category);
        $this->assertSame('E-commerce', $category->title);
        $this->assertSame('e-commerce', $category->code);
    }

    public function test_plain_json_string_and_markdown_wrapper_are_accepted(): void
    {
        // Модели время от времени упрощают формат до голой строки и/или
        // оборачивают ответ в ```json — это не порча ответа.
        $this->fakeAnswer("```json\n\"Shipping\"\n```");

        $id = $this->service()->detect('USPS labels');

        $this->assertSame('Shipping', Category::find($id)?->title);
    }

    public function test_garbage_answer_returns_null_and_marks_call_rejected(): void
    {
        $this->fakeAnswer('Извините, я не могу выбрать категорию для этой статьи.');

        $this->assertNull($this->service()->detect('USPS labels'));
        $this->assertSame(0, Category::count(), 'мусорный ответ не должен создавать категории');
        // Токены потрачены, пользы нет: вызов перекрашен, а не удалён —
        // доля такого брака видна в журнале расходов.
        $this->assertDatabaseHas('llm_calls', ['kind' => LlmCall::KIND_CATEGORY, 'outcome' => LlmCall::OUTCOME_REJECTED]);
    }

    public function test_multiline_or_too_long_name_is_garbage(): void
    {
        // Перенос строки — модель добавила пояснение или варианты; разделу
        // нужна одна короткая строка.
        $this->fakeAnswer('{"category": "'.str_repeat('Long', 20).'"}');

        $this->assertNull($this->service()->detect('USPS labels'));

        $this->fakeAnswer("\"Shipping\nSecond line\"");

        $this->assertNull($this->service()->detect('USPS labels'));
        $this->assertSame(0, Category::count());
    }

    public function test_api_error_returns_null_and_does_not_throw(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->assertNull($this->service()->detect('USPS labels'));
        $this->assertDatabaseHas('llm_calls', ['kind' => LlmCall::KIND_CATEGORY, 'outcome' => LlmCall::OUTCOME_ERROR]);
    }

    public function test_down_main_model_falls_through_to_the_next_one(): void
    {
        // Квота у Google считается ПО МОДЕЛИ: исчерпавшая лимит основная —
        // не повод останавливать разметку, пока у запасной квота свободна.
        config(['translation.circuit_breaker_seconds' => 300]);
        FallbackTranslator::markDown('gemini-3.6-flash');

        $this->fakeAnswer('{"category": "Shipping"}');

        $id = $this->service()->detect('USPS labels');

        $this->assertSame('Shipping', Category::find($id)?->title);
        // Запрос ушёл ровно один и именно к запасной модели: разомкнутую
        // основную не трогаем, чтобы не жечь время парсинга.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'models/gemini-3.5-flash:'));
        $this->assertDatabaseHas('llm_calls', [
            'kind' => LlmCall::KIND_CATEGORY,
            'model' => 'gemini-3.5-flash',
            'outcome' => LlmCall::OUTCOME_OK,
        ]);
    }

    public function test_quota_error_on_main_model_falls_through_to_the_next_one(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'models/gemini-3.6-flash:')) {
                return Http::response(['error' => ['message' => 'quota exceeded']], 429);
            }

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"category": "Shipping"}']]],
                    'finishReason' => 'STOP',
                ]],
            ]);
        });

        $id = $this->service()->detect('USPS labels');

        $this->assertSame('Shipping', Category::find($id)?->title);
        // Оба захода в журнале, каждый под своей моделью: по ним видно и
        // отказ основной, и то, кто реально разметил статью.
        $this->assertDatabaseHas('llm_calls', [
            'kind' => LlmCall::KIND_CATEGORY,
            'model' => 'gemini-3.6-flash',
            'outcome' => LlmCall::OUTCOME_ERROR,
        ]);
        $this->assertDatabaseHas('llm_calls', [
            'kind' => LlmCall::KIND_CATEGORY,
            'model' => 'gemini-3.5-flash',
            'outcome' => LlmCall::OUTCOME_OK,
        ]);
    }

    public function test_all_models_down_returns_null_without_network(): void
    {
        // Разметка — не перевод: когда недоступны ВСЕ модели цепочки,
        // выжидать таймаут на каждом посте ради заведомо провального запроса
        // незачем.
        config(['translation.circuit_breaker_seconds' => 300]);
        FallbackTranslator::markDown('gemini-3.6-flash');
        FallbackTranslator::markDown('gemini-3.5-flash');

        Http::fake();

        $this->assertNull($this->service()->detect('USPS labels'));
        Http::assertNothingSent();
        $this->assertSame(0, LlmCall::where('kind', LlmCall::KIND_CATEGORY)->count());
    }

    public function test_disabled_feature_returns_null_without_network(): void
    {
        config(['tagging.llm_category_enabled' => false]);
        Http::fake();

        $this->assertNull($this->service()->detect('USPS labels'));
        Http::assertNothingSent();
    }

    public function test_post_store_falls_back_to_llm_when_dictionary_misses(): void
    {
        $shipping = Category::create(['title' => 'Shipping', 'code' => 'shipping']);

        $this->fakeAnswer('{"category": "Shipping"}');

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Integrating a US store with USPS labels',
            'content' => '<p>How to wire checkout to postage rates and print labels.</p>',
            'url' => 'https://example.test/usps-labels',
        ])));

        $this->assertSame($shipping->id, $post->category_id);
    }

    public function test_llm_is_not_called_when_dictionary_matches(): void
    {
        $laravel = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

        Http::fake();

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Laravel queues in practice',
            'content' => '<p>content</p>',
            'url' => 'https://example.test/laravel-queues',
        ])));

        $this->assertSame($laravel->id, $post->category_id);
        Http::assertNothingSent();
        $this->assertSame(0, LlmCall::where('kind', LlmCall::KIND_CATEGORY)->count());
    }

    public function test_post_is_saved_even_when_llm_fails(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $post = Post::withoutSyncingToSearch(fn () => app(PostService::class)->store(PostData::fromArray([
            'title' => 'Integrating a US store with USPS labels',
            'content' => '<p>content</p>',
            'url' => 'https://example.test/usps-category-fail',
        ])));

        $this->assertTrue($post->exists, 'сбой выбора категории не должен стоить поста');
        $this->assertNull($post->category_id);
    }

    private function service(): LlmCategoryService
    {
        return app(LlmCategoryService::class);
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

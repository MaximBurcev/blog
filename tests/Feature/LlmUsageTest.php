<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Analytics\Widgets\LlmUsage;
use App\Filament\Widgets\ParsingStatusOverview;
use App\Models\LlmCall;
use App\Models\User;
use App\Service\Translation\FallbackTranslator;
use App\Service\Translation\GeminiTranslator;
use App\Service\Translation\TranslatedHtmlValidator;
use App\Support\LlmPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Учёт расходов на языковую модель.
 *
 * Тесты здесь про две вещи, каждая из которых уже подводила проект. Первая —
 * что учёт вообще ведётся: неработающий OCR полгода выглядел как «на картинке
 * нет текста», и молчаливо не пишущийся журнал был бы тем же самым. Вторая —
 * что учёт не мешает делу: запись расхода не имеет права стоить нам статьи.
 */
class LlmUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'translation.gemini.key' => 'test-key',
            'translation.gemini.model' => 'gemini-3.6-flash',
            'translation.gemini.retry_delays_ms' => [0],
            'translation.circuit_breaker_seconds' => 0,
            'translation.gemini.prices' => [],
        ]);
    }

    public function test_successful_translation_records_tokens_from_the_answer(): void
    {
        $this->fakeAnswer('<p>Перевод.</p>', usage: [
            'promptTokenCount' => 1200,
            'candidatesTokenCount' => 800,
            'thoughtsTokenCount' => 150,
        ]);

        $this->translator()->translateHtml('<p>Source.</p>');

        $call = LlmCall::sole();

        $this->assertSame(LlmCall::OUTCOME_OK, $call->outcome);
        $this->assertSame(LlmCall::KIND_HTML, $call->kind);
        $this->assertSame('gemini', $call->engine);
        $this->assertSame('gemini-3.6-flash', $call->model);
        $this->assertSame(1200, $call->prompt_tokens);
        $this->assertSame(800, $call->output_tokens);
        // Счётчик «размышлений» отдельно от ответа: тарифицируется он как
        // выходной, но растёт от thinking_level, а не от длины статьи.
        $this->assertSame(150, $call->thinking_tokens);
        $this->assertSame(200, $call->http_status);
        $this->assertSame(1, $call->attempts);
        $this->assertNotNull($call->duration_ms);
    }

    public function test_prompt_and_answer_are_not_stored(): void
    {
        // Тело статьи и так лежит в posts.content_orig. Второй экземпляр рядом
        // с расходами удвоил бы бэкап ради данных, на которые ни один отчёт не
        // смотрит.
        $this->fakeAnswer('<p>Секретный перевод.</p>');

        $this->translator()->translateHtml('<p>Secret source.</p>');

        $row = (array) LlmCall::sole()->getAttributes();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString('Secret source', (string) $value, "колонка {$column}");
            $this->assertStringNotContainsString('Секретный перевод', (string) $value, "колонка {$column}");
        }
    }

    public function test_rejection_reason_does_not_leak_the_article_into_the_journal(): void
    {
        // TranslatedHtmlValidator дописывает к причине улику — до 60 символов
        // кода из самой статьи. В журнал расходов она попасть не должна: он по
        // построению не хранит ни промпта, ни ответа. Заодно с уликой каждый
        // брак становился бы уникальной строкой, и отчёт «по каким причинам
        // браковали» выродился бы в список из N причин вместо трёх с числами.
        $this->fakeAnswer('<p>Команда <code>queue:listen --tries=99</code> запускает воркер.</p>');

        $this->translator()->translateHtml(
            '<p>The <code>queue:work --tries=3</code> command starts a worker.</p>'
        );

        $error = (string) LlmCall::sole()->error;

        $this->assertSame('код изменён или потерян', $error);
        $this->assertStringNotContainsString('queue:work', $error);
    }

    public function test_rejected_answer_stays_in_the_journal_as_waste(): void
    {
        // Ответ пришёл и оплачен, но код в нём переписан — валидатор такой
        // перевод не пропустит. Удалить строку значило бы занизить расход
        // ровно на ту его часть, которая потрачена впустую.
        $this->fakeAnswer('<p>Команда <code>queue:listen</code> запускает воркер.</p>', usage: [
            'promptTokenCount' => 500,
            'candidatesTokenCount' => 400,
        ]);

        $result = $this->translator()->translateHtml(
            '<p>The <code>queue:work</code> command starts a worker.</p>'
        );

        $this->assertTrue($result->failed);

        $call = LlmCall::sole();

        $this->assertSame(LlmCall::OUTCOME_REJECTED, $call->outcome);
        $this->assertSame(500, $call->prompt_tokens);
        $this->assertNotNull($call->error);
    }

    public function test_truncated_answer_is_recorded_with_its_tokens(): void
    {
        // За обрезанный ответ мы платим полностью, а статьи не получаем —
        // самый дорогой из отказов, и в журнале он обязан быть виден.
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '<p>Половина</p>']]],
                    'finishReason' => 'MAX_TOKENS',
                ]],
                'usageMetadata' => ['promptTokenCount' => 9000, 'candidatesTokenCount' => 32768],
            ]),
        ]);

        $this->translator()->translateHtml('<p>Very long article.</p>');

        $call = LlmCall::sole();

        $this->assertSame(LlmCall::OUTCOME_TRUNCATED, $call->outcome);
        $this->assertSame('MAX_TOKENS', $call->finish_reason);
        $this->assertSame(32768, $call->output_tokens);
    }

    public function test_api_error_is_recorded_with_status(): void
    {
        Http::fake(['*' => Http::response(['error' => 'User location is not supported'], 400)]);

        $this->translator()->translateHtml('<p>Text</p>');

        $call = LlmCall::sole();

        $this->assertSame(LlmCall::OUTCOME_ERROR, $call->outcome);
        $this->assertSame(400, $call->http_status);
        $this->assertStringContainsString('location', (string) $call->error);
    }

    public function test_call_that_never_left_the_process_has_no_duration(): void
    {
        // Ноль миллисекунд означал бы мгновенный ответ и занижал бы среднюю
        // длительность по всем остальным вызовам, поэтому именно NULL.
        config(['translation.gemini.key' => '']);
        Http::fake();

        $this->translator()->translateHtml('<p>Text</p>');

        $call = LlmCall::sole();

        $this->assertSame(LlmCall::OUTCOME_NO_KEY, $call->outcome);
        $this->assertNull($call->duration_ms);
        $this->assertSame(0, $call->attempts);
    }

    public function test_retries_are_counted(): void
    {
        // Задержки между попытками доходят до 45 секунд, и рост этого счётчика
        // — единственное объяснение того, почему разбор статей вдруг пополз.
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => 'rate limit'], 429)
                ->push([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => '<p>Перевод.</p>']]],
                        'finishReason' => 'STOP',
                    ]],
                ], 200),
        ]);

        $this->translator()->translateHtml('<p>Source.</p>');

        $call = LlmCall::sole();

        $this->assertSame(LlmCall::OUTCOME_OK, $call->outcome);
        $this->assertSame(2, $call->attempts);
    }

    public function test_spent_quota_stops_the_engine_instead_of_retrying_every_article(): void
    {
        // 429, переживший всю цепочку повторов, ждал дольше минутного окна —
        // значит поминутный лимит успел смениться и дело не в нём. Суточная
        // квота не восстановится ни через минуту, ни через пять, и без паузы
        // каждая следующая статья платит за тот же отказ полной цепочкой
        // ожиданий: на проде так ушло сорок минут воркера.
        config([
            'translation.circuit_breaker_seconds' => 300,
            'translation.quota_pause_seconds' => 3600,
            'translation.gemini.retry_delays_ms' => [0, 0, 61_000],
        ]);

        // Иначе прогон честно спит эту минуту: Http::fake подделывает ответы,
        // но не паузы между попытками. syncWithCarbon двигает часы на время
        // подделанного сна — иначе «сколько мы прождали» останется нулём.
        Sleep::fake(syncWithCarbon: true);

        Http::fake(['*' => Http::response(['error' => ['code' => 429]], 429)]);

        $this->translator()->translateHtml('<p>Text</p>');

        $this->assertTrue(FallbackTranslator::isDown('gemini'));

        // Проверяется именно ЧАСОВАЯ пауза, а не факт размыкания: с обычными
        // пятью минутами движок ожил бы прямо к следующей статье, и весь смысл
        // отличать квоту от темпа пропал бы. Булев isDown() этого не ловит.
        $this->travel(11)->minutes();
        $this->assertTrue(FallbackTranslator::isDown('gemini'), 'пауза оказалась короче квотной');

        $this->travel(50)->minutes();
        $this->assertFalse(FallbackTranslator::isDown('gemini'), 'пауза не должна быть вечной');
    }

    public function test_a_short_burst_does_not_disable_the_engine(): void
    {
        // Ждали меньше минуты — ничего не доказано: поминутный лимит мог и не
        // успеть смениться, и пауза была бы наказанием за всплеск.
        config([
            'translation.circuit_breaker_seconds' => 300,
            'translation.gemini.retry_delays_ms' => [0, 0, 5_000],
        ]);

        Sleep::fake(syncWithCarbon: true);

        Http::fake(['*' => Http::response(['error' => ['code' => 429]], 429)]);

        $this->translator()->translateHtml('<p>Text</p>');

        $this->assertFalse(FallbackTranslator::isDown('gemini'));
    }

    public function test_quota_pause_is_not_cut_short_by_an_ordinary_failure(): void
    {
        // Рядовая пятиминутная пауза, легшая поверх часовой квотной, вернула
        // бы нас к заведомо провальным попыткам через пять минут — и так по
        // кругу до самого сброса квоты.
        config(['translation.circuit_breaker_seconds' => 300]);

        FallbackTranslator::markDown('gemini', 3600);
        FallbackTranslator::markDown('gemini');

        $this->travel(11)->minutes();

        $this->assertTrue(FallbackTranslator::isDown('gemini'));
    }

    public function test_missing_quota_pause_setting_still_disables_the_engine(): void
    {
        // Ноль в настройке — это «значения нет», а не «паузы не надо».
        // Пропустив её, фикс молча не срабатывал бы, а лог обещал паузу.
        config([
            'translation.circuit_breaker_seconds' => 300,
            'translation.quota_pause_seconds' => 0,
            'translation.gemini.retry_delays_ms' => [0, 0, 61_000],
        ]);

        Sleep::fake(syncWithCarbon: true);

        Http::fake(['*' => Http::response(['error' => ['code' => 429]], 429)]);

        $this->translator()->translateHtml('<p>Text</p>');

        $this->assertTrue(FallbackTranslator::isDown('gemini'));
    }

    public function test_journal_failure_does_not_cost_us_the_translation(): void
    {
        // Учёт — побочная функция. Недоступная таблица (окно деплоя, диск) не
        // повод терять статью, ради которой всё затевалось.
        $this->fakeAnswer('<p>Перевод.</p>');

        Schema::drop('llm_calls');

        $result = $this->translator()->translateHtml('<p>Source.</p>');

        $this->assertFalse($result->failed);
        $this->assertSame('<p>Перевод.</p>', $result->text);
    }

    public function test_cost_is_unknown_until_the_price_is_configured(): void
    {
        $this->assertFalse(LlmPricing::isKnown('gemini-3.6-flash'));
        $this->assertNull(LlmPricing::costUsd('gemini-3.6-flash', 1_000_000, 1_000_000));

        config(['translation.gemini.prices' => [
            'gemini-3.6-flash' => ['input' => 0.30, 'output' => 2.50],
        ]]);

        $this->assertTrue(LlmPricing::isKnown('gemini-3.6-flash'));
        $this->assertEqualsWithDelta(2.80, LlmPricing::costUsd('gemini-3.6-flash', 1_000_000, 1_000_000), 0.0001);
        // Цена задаётся на модель, а не на движок: со сменой GEMINI_MODEL
        // история обязана считаться по той цене, по которой её купили.
        $this->assertNull(LlmPricing::costUsd('gemini-3.5-flash', 1000, 1000));
    }

    public function test_widget_counts_only_calls_inside_the_period(): void
    {
        $this->recordCall(daysAgo: 1, promptTokens: 100, outputTokens: 50);
        $this->recordCall(daysAgo: 2, promptTokens: 200, outputTokens: 60);
        $this->recordCall(daysAgo: 40, promptTokens: 999_999, outputTokens: 999_999);

        $stats = $this->statsFor(['period' => 7]);

        $this->assertSame('2', $stats['Запросов за неделю']);
        $this->assertSame('410', $stats['Токенов за неделю']);
    }

    public function test_widget_reports_share_of_good_answers(): void
    {
        $this->recordCall(daysAgo: 1);
        $this->recordCall(daysAgo: 1);
        $this->recordCall(daysAgo: 1, outcome: LlmCall::OUTCOME_REJECTED);
        $this->recordCall(daysAgo: 1, outcome: LlmCall::OUTCOME_ERROR);

        $this->assertSame('50%', $this->statsFor(['period' => 7])['Удачных ответов']);

        // Подпись проверяется наравне со значением: счётчик ошибок однажды уже
        // не выбирался запросом вовсе, и плитка сообщала «50%» вместе с «ни
        // одного отказа за период» — по значению такую регрессию не поймать.
        $description = $this->statDescriptionsFor(['period' => 7])['Удачных ответов'];

        $this->assertStringContainsString('Брак модели: 1', $description);
        $this->assertStringContainsString('ошибок и пропусков: 1', $description);
    }

    public function test_widget_does_not_call_silent_failures_a_clean_period(): void
    {
        // Отказы без брака: ключ отвалился, все вызовы кончились ничем. Раньше
        // такой период рапортовал «ни одного отказа».
        $this->recordCall(daysAgo: 1);
        $this->recordCall(daysAgo: 1, outcome: LlmCall::OUTCOME_ERROR);
        $this->recordCall(daysAgo: 1, outcome: LlmCall::OUTCOME_NO_KEY);

        $description = $this->statDescriptionsFor(['period' => 7])['Удачных ответов'];

        $this->assertStringNotContainsString('Ни одного отказа', $description);
        $this->assertStringContainsString('ошибок и пропусков: 2', $description);
    }

    public function test_average_response_time_ignores_titles_and_retries(): void
    {
        // Заголовок отвечает за полсекунды против десятков секунд у статьи, а
        // длительность с повторами включает сон между попытками (до 45 с).
        // Смешав их, получаем число, которое не описывает ничего.
        $this->recordCall(daysAgo: 1, durationMs: 20_000);
        $this->recordCall(daysAgo: 1, kind: LlmCall::KIND_TEXT, durationMs: 500);
        $this->recordCall(daysAgo: 1, durationMs: 47_000, attempts: 2);

        $stats = $this->statsFor(['period' => 7]);

        $this->assertSame('20,0 с', $stats['Среднее время ответа']);
        $this->assertStringContainsString(
            'Повторных попыток: 1',
            $this->statDescriptionsFor(['period' => 7])['Среднее время ответа']
        );
    }

    public function test_widget_converts_tokens_to_money_when_the_price_is_known(): void
    {
        config(['translation.gemini.prices' => [
            'gemini-3.6-flash' => ['input' => 1.0, 'output' => 1.0],
        ]]);

        $this->recordCall(daysAgo: 1, promptTokens: 500_000, outputTokens: 500_000);

        $stats = $this->statsFor(['period' => 7]);

        $this->assertSame('$1.00', $stats['Стоимость за неделю']);
    }

    public function test_widget_admits_that_the_price_is_not_configured(): void
    {
        // Ноль долларов и «мы не знаем, сколько это стоило» — разные
        // утверждения, и первое выглядит убедительнее ровно настолько,
        // насколько оно неверно.
        $this->recordCall(daysAgo: 1, promptTokens: 500_000, outputTokens: 500_000);

        $stats = $this->statsFor(['period' => 7]);

        $this->assertSame('—', $stats['Стоимость за неделю']);
    }

    public function test_widget_survives_a_period_without_any_calls(): void
    {
        $stats = $this->statsFor(['period' => 7]);

        $this->assertSame('0', $stats['Запросов за неделю']);
    }

    public function test_widget_ignores_a_model_whose_price_is_unknown(): void
    {
        config(['translation.gemini.prices' => [
            'gemini-3.6-flash' => ['input' => 1.0, 'output' => 1.0],
        ]]);

        $this->recordCall(daysAgo: 1, promptTokens: 1_000_000, outputTokens: 0);
        $this->recordCall(daysAgo: 1, model: 'gemini-4.0-flash', promptTokens: 1_000_000, outputTokens: 0);

        $stats = $this->statsFor(['period' => 7]);

        // Сумма считается по тому, что известно, а остаток назван вслух:
        // молча прибавить чужие токены по чужой цене было бы выдумкой.
        $this->assertSame('$1.00', $stats['Стоимость за неделю']);
        $this->assertStringContainsString('Без цены', $this->statDescriptionsFor(['period' => 7])['Стоимость за неделю']);
    }

    public function test_prune_keeps_the_window_analytics_still_reads(): void
    {
        $this->recordCall(daysAgo: 200);
        $this->recordCall(daysAgo: 100);

        $this->artisan('llm-calls:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, LlmCall::count(), 'dry-run не должен удалять');

        $this->artisan('llm-calls:prune')->assertSuccessful();

        $this->assertSame(1, LlmCall::count());
        // Аналитика смотрит максимум на квартал, поэтому переживший вызов —
        // это тот, который она ещё может прочитать.
        $this->assertSame(100, (int) LlmCall::sole()->created_at->diffInDays(now()));
    }

    public function test_prune_refuses_a_nonsense_retention(): void
    {
        // Приведение типа молча превратило бы опечатку в «оставить один день»,
        // то есть в удаление почти всего журнала.
        $this->recordCall(daysAgo: 1);

        $this->artisan('llm-calls:prune', ['--days' => 'неделя'])->assertFailed();

        $this->assertSame(1, LlmCall::count());
    }

    public function test_dashboard_tile_does_not_promise_a_fallback_that_is_switched_off(): void
    {
        // При TRANSLATION_FALLBACK=false контейнер отдаёт голый GeminiTranslator:
        // скрейпера в цепочке нет вовсе, и отказ модели означает не «переведёт
        // скрейпер», а «статья останется без перевода».
        config(['translation.fallback' => false, 'translation.gemini.key' => '']);

        $description = $this->parsingStatDescriptions()['Переводчик'];

        $this->assertStringContainsString('остаются без перевода', $description);
        $this->assertStringNotContainsString('скрейпер', $description);
    }

    public function test_dashboard_tile_reports_a_disabled_engine(): void
    {
        config(['translation.circuit_breaker_seconds' => 300]);
        FallbackTranslator::markDown('gemini');

        $stats = $this->parsingStats();

        $this->assertSame('не работает', $stats['Переводчик']);
        $this->assertStringContainsString('скрейпер', $this->parsingStatDescriptions()['Переводчик']);
    }

    public function test_dashboard_tile_is_green_while_the_model_answers(): void
    {
        $this->assertSame('модель', $this->parsingStats()['Переводчик']);
    }

    /**
     * @return array<string, string>
     */
    private function parsingStats(): array
    {
        return $this->mapWidgetStats(ParsingStatusOverview::class, [], fn ($stat) => (string) $stat->getValue());
    }

    /**
     * @return array<string, string>
     */
    private function parsingStatDescriptions(): array
    {
        return $this->mapWidgetStats(ParsingStatusOverview::class, [], fn ($stat) => (string) $stat->getDescription());
    }

    private function recordCall(
        int $daysAgo,
        string $outcome = LlmCall::OUTCOME_OK,
        string $model = 'gemini-3.6-flash',
        string $kind = LlmCall::KIND_HTML,
        int $promptTokens = 0,
        int $outputTokens = 0,
        int $attempts = 1,
        int $durationMs = 1000,
    ): void {
        LlmCall::create([
            'engine' => 'gemini',
            'model' => $model,
            'kind' => $kind,
            'outcome' => $outcome,
            'prompt_tokens' => $promptTokens,
            'output_tokens' => $outputTokens,
            'thinking_tokens' => 0,
            'duration_ms' => $durationMs,
            'attempts' => $attempts,
            'http_status' => 200,
        ])->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function statsFor(array $filters): array
    {
        return $this->mapWidgetStats(LlmUsage::class, $filters, fn ($stat) => (string) $stat->getValue());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function statDescriptionsFor(array $filters): array
    {
        return $this->mapWidgetStats(LlmUsage::class, $filters, fn ($stat) => (string) $stat->getDescription());
    }

    /**
     * Значения плиток берутся из самого виджета, а не из отрендеренного HTML:
     * assertSee по разметке ломается от вёрстки Filament и не отличает «2» в
     * счётчике от «2» в соседней подписи (тот же приём, что в
     * AnalyticsPageTest).
     *
     * @param  class-string  $widgetClass
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function mapWidgetStats(string $widgetClass, array $filters, callable $extract): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $widget = Livewire::actingAs($admin)
            ->test($widgetClass, $filters === [] ? [] : ['filters' => $filters])
            ->instance();

        $method = new \ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        $map = [];

        foreach ($method->invoke($widget) as $stat) {
            $map[(string) $stat->getLabel()] = $extract($stat);
        }

        return $map;
    }

    private function translator(): GeminiTranslator
    {
        return new GeminiTranslator(app(HttpFactory::class), new TranslatedHtmlValidator);
    }

    /**
     * @param  array<string, int>  $usage
     */
    private function fakeAnswer(string $text, array $usage = []): void
    {
        Http::fake([
            '*' => Http::response(array_filter([
                'candidates' => [[
                    'content' => ['parts' => [['text' => $text]]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => $usage ?: null,
            ])),
        ]);
    }
}

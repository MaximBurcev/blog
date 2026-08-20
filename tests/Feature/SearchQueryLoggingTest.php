<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Analytics\Widgets\SearchQueries;
use App\Models\Category;
use App\Models\Post;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Поиск по сайту — единственное место, где читатель прямым текстом говорит,
 * чего ему не хватает. До 20.08.2026 эти запросы никуда не записывались.
 */
class SearchQueryLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Драйвер collection ищет прямо по таблице, без Meilisearch: тест
        // проходит настоящий путь контроллера, а не подменённый билдер.
        config(['scout.driver' => 'collection']);
    }

    public function test_query_is_recorded_with_result_count(): void
    {
        $this->get('/search?q=Laravel+Octane')->assertOk();

        $logged = SearchQuery::sole();

        $this->assertSame('laravel octane', $logged->query);
        $this->assertSame(0, $logged->results_count);
    }

    public function test_successful_query_records_how_many_were_found(): void
    {
        Post::withoutSyncingToSearch(function (): void {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            foreach (['queues-one', 'queues-two'] as $code) {
                Post::create([
                    'title' => 'Очереди в Laravel',
                    'code' => $code,
                    'content' => 'Текст про очереди',
                    'published' => true,
                    'category_id' => $category->id,
                ]);
            }
        });

        $this->get('/search?q=Очереди')->assertOk();

        // Отчёт делит запросы на нашедшие и пустые — значит счётчик должен
        // быть настоящим, а не всегда нулём.
        $this->assertSame(2, SearchQuery::sole()->results_count);
    }

    public function test_crawler_queries_are_not_recorded(): void
    {
        // /search закрыт в robots.txt, но послушны не все: краулер, прошедший
        // по ссылкам с ?q=, наполнил бы отчёт «искали и не нашли» выдумками,
        // которых ни один читатель не набирал.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])
            ->get('/search?q=laravel')
            ->assertOk();

        $this->assertSame(0, SearchQuery::count());
    }

    public function test_who_searched_is_not_stored(): void
    {
        $this->get('/search?q=что-то');

        // Запрос чувствительнее адреса страницы, а на вопрос «о чём писать»
        // отвечает его текст, без привязки к человеку.
        $columns = array_keys(SearchQuery::sole()->getAttributes());

        foreach (['ip', 'ip_hash', 'session_hash', 'user_agent', 'user_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_variants_of_the_same_query_collapse(): void
    {
        foreach (['Laravel', 'laravel', '  LARAVEL  '] as $variant) {
            $this->get('/search?q='.urlencode($variant));
        }

        // Иначе один и тот же вопрос растащило бы на три строки отчёта, и
        // главное — «что спрашивают чаще всего» — потерялось бы.
        $this->assertSame(1, SearchQuery::distinct()->count('query'));
        $this->assertSame(3, SearchQuery::count());
    }

    public function test_pagination_does_not_multiply_the_query(): void
    {
        $this->get('/search?q=laravel');
        $this->get('/search?q=laravel&page=2');
        $this->get('/search?q=laravel&page=3');

        // Читатель, пролиставший выдачу, спросил один раз.
        $this->assertSame(1, SearchQuery::count());
    }

    public function test_broken_search_engine_is_not_recorded_as_a_gap(): void
    {
        // Meilisearch — отдельный контейнер. Его падение даёт ноль результатов,
        // но это «сервис лежит», а не «на сайте такого нет»: записав такое, мы
        // получили бы фантомные темы в отчёте «искали и не нашли».
        config(['scout.driver' => 'nonexistent-engine']);

        $this->get('/search?q=laravel')->assertOk();

        $this->assertSame(0, SearchQuery::count());
    }

    /**
     * mb_strtolower не сохраняет длину: 191 символ «İ» превращается в 382
     * (U+0130 раскладывается в пару). Обрезав до понижения регистра, мы отдали
     * бы в VARCHAR(191) строку вдвое длиннее — MySQL в strict-режиме ответил бы
     * «Data too long», а исключение унесло бы текст запроса в лог. Вызывается
     * одной ссылкой из адресной строки.
     */
    public function test_case_folding_cannot_overflow_the_column(): void
    {
        $this->get('/search?q='.urlencode(str_repeat('İ', 191)))->assertOk();

        $logged = SearchQuery::sole();

        $this->assertLessThanOrEqual(SearchQuery::MAX_LENGTH, mb_strlen($logged->query));
    }

    public function test_query_is_escaped_in_the_widget(): void
    {
        // Текст полностью подконтролен читателю: страж на случай, если кто-то
        // однажды выведет его через {!! !!} ради подсветки совпадений.
        SearchQuery::create(['query' => '<script>alert(1)</script>', 'results_count' => 0]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)
            ->test(SearchQueries::class, ['filters' => ['period' => 7]])
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertSee('&lt;script&gt;', escape: false);
    }

    /**
     * Закрытый пробел перестаёт числиться дырой.
     *
     * Запрос сперва ничего не находил, потом появилась статья — держать его в
     * «искали и не нашли» ещё до трёх месяцев значит показывать работу
     * несделанной.
     */
    public function test_query_that_started_finding_leaves_the_gap_list(): void
    {
        SearchQuery::create(['query' => 'laravel octane', 'results_count' => 0]);
        SearchQuery::create(['query' => 'laravel octane', 'results_count' => 2]);

        $data = $this->widgetData();

        $this->assertSame([], $data['missing']->pluck('query')->all());
        $this->assertSame(['laravel octane'], $data['found']->pluck('query')->all());
    }

    public function test_widget_puts_unanswered_queries_first(): void
    {
        SearchQuery::create(['query' => 'laravel octane', 'results_count' => 0]);
        SearchQuery::create(['query' => 'laravel octane', 'results_count' => 0]);
        SearchQuery::create(['query' => 'очереди', 'results_count' => 4]);

        $data = $this->widgetData();

        $this->assertSame(['laravel octane'], $data['missing']->pluck('query')->all());
        $this->assertSame(2, (int) $data['missing']->first()->times);
        $this->assertSame(['очереди'], $data['found']->pluck('query')->all());
        $this->assertSame(3, $data['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function widgetData(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $widget = Livewire::actingAs($admin)
            ->test(SearchQueries::class, ['filters' => ['period' => 7]])
            ->assertSuccessful()
            ->instance();

        return (new ReflectionMethod($widget, 'getViewData'))->invoke($widget);
    }
}

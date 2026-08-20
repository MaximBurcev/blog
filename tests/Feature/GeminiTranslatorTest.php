<?php

namespace Tests\Feature;

use App\Service\Translation\FallbackTranslator;
use App\Service\Translation\GeminiTranslator;
use App\Service\Translation\TranslatedHtmlValidator;
use App\Service\Translation\TranslationResult;
use App\Service\Translation\Translator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Перевод через LLM.
 *
 * Скрейпер был туп, но предсказуем. Модель умеет всё — в том числе переписать
 * код, выбросить абзац или вернуть извинения вместо разметки, поэтому тесты
 * здесь про недоверие к ответу, а не про счастливый путь.
 */
class GeminiTranslatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'translation.gemini.key' => 'test-key',
            'translation.gemini.model' => 'gemini-3.6-flash',
            // Без задержек: иначе прогон упирается в реальные 15 и 45 секунд.
            'translation.gemini.retry_delays_ms' => [0],
            // Размыкатель проверяется отдельным тестом; в остальных он мешал бы
            // — отказ в одном тесте отключал бы движок в следующих.
            'translation.circuit_breaker_seconds' => 0,
        ]);
    }

    public function test_markup_and_code_survive_translation(): void
    {
        $source = '<p>The <code>queue:work</code> command starts a worker.</p>'
            .'<pre><code class="language-php">Bus::batch([])->dispatch();</code></pre>';

        $answer = '<p>Команда <code>queue:work</code> запускает воркер.</p>'
            .'<pre><code class="language-php">Bus::batch([])->dispatch();</code></pre>';

        $this->fakeAnswer($answer);

        $result = $this->translator()->translateHtml($source);

        $this->assertFalse($result->failed);
        $this->assertSame('gemini', $result->engine);
        $this->assertStringContainsString('Bus::batch([])->dispatch();', $result->text);
        $this->assertStringContainsString('class="language-php"', $result->text);
    }

    public function test_rewritten_code_is_rejected(): void
    {
        // Самая дорогая ошибка из возможных: читатель копирует пример из
        // статьи и получает не работающий код. Такой ответ не сохраняем.
        $source = '<pre><code>Bus::batch([])->dispatch();</code></pre>';

        $this->fakeAnswer('<pre><code>Автобус::пакет([])->отправить();</code></pre>');

        $result = $this->translator()->translateHtml($source);

        $this->assertTrue($result->failed);
        $this->assertSame($source, $result->text, 'исходник должен остаться нетронутым');
    }

    public function test_truncated_answer_is_rejected(): void
    {
        // Обрезанный ответ выглядит здоровым: валидный HTML, код цел — просто
        // без хвоста статьи. Ловится только по finishReason.
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '<p>Начало статьи</p>']]],
                    'finishReason' => 'MAX_TOKENS',
                ]],
            ]),
        ]);

        $result = $this->translator()->translateHtml('<p>A long article</p><p>Second half</p>');

        $this->assertTrue($result->failed);
    }

    public function test_markdown_wrapper_is_stripped_not_rejected(): void
    {
        // Модель регулярно оборачивает ответ в ```html, даже когда просили не
        // делать этого. Это формат ответа, а не порча разметки.
        $this->fakeAnswer("```html\n<p>Перевод абзаца.</p>\n```");

        $result = $this->translator()->translateHtml('<p>A paragraph.</p>');

        $this->assertFalse($result->failed);
        $this->assertSame('<p>Перевод абзаца.</p>', $result->text);
    }

    public function test_model_refusal_is_rejected(): void
    {
        // Отказ — валидный HTML без кода, и проверка кода его пропускала.
        // Для статьи без <pre> это означало «Извините, я не могу» вместо
        // текста статьи на сайте.
        $source = '<p>'.str_repeat('A meaningful paragraph about queues. ', 10).'</p>';

        $this->fakeAnswer('<p>Извините, я не могу выполнить этот запрос.</p>');

        $result = $this->translator()->translateHtml($source);

        $this->assertTrue($result->failed);
        $this->assertSame($source, $result->text);
    }

    public function test_dropped_paragraphs_are_rejected(): void
    {
        // Пересказ вместо перевода: валидный HTML, код не тронут, но выброшены
        // три четверти статьи.
        $source = '';
        for ($i = 0; $i < 8; $i++) {
            $source .= '<p>'.str_repeat("Paragraph {$i} with meaningful content. ", 5).'</p>';
        }

        $this->fakeAnswer('<p>Краткий пересказ всей статьи одним абзацем.</p>');

        $result = $this->translator()->translateHtml($source);

        $this->assertTrue($result->failed);
    }

    public function test_untranslated_answer_is_rejected(): void
    {
        // Модель вернула оригинал — кириллицы нет, значит перевода не было.
        $source = '<p>'.str_repeat('The queue worker processes jobs. ', 8).'</p>';

        $this->fakeAnswer($source);

        $result = $this->translator()->translateHtml($source);

        $this->assertTrue($result->failed);
    }

    public function test_markdown_wrapper_without_trailing_newline_is_stripped(): void
    {
        // Модели регулярно закрывают фенс без переноса строки. Строгий шаблон
        // оставлял бэктики прямо в тексте статьи.
        $this->fakeAnswer("```html\n<p>Перевод этого абзаца статьи.</p>```");

        $result = $this->translator()->translateHtml('<p>A paragraph of this article.</p>');

        $this->assertFalse($result->failed);
        $this->assertStringNotContainsString('`', $result->text);
    }

    public function test_title_is_translated(): void
    {
        $this->fakeAnswer('Две ошибки при вызове CLI из Laravel');

        $result = $this->translator()->translateText('Two mistakes when your Laravel app shells out');

        $this->assertFalse($result->failed);
        $this->assertSame('Две ошибки при вызове CLI из Laravel', $result->text);
    }

    public function test_chatty_title_answer_is_rejected(): void
    {
        // posts.title — varchar(255), и в strict-режиме длинный ответ роняет
        // сохранение целиком: теряется весь пост, а не только перевод. Плюс из
        // заголовка считается публичный адрес статьи.
        $this->fakeAnswer("Вот перевод заголовка:\n\n1. Первый вариант\n2. Второй вариант");

        $result = $this->translator()->translateText('Two mistakes');

        $this->assertTrue($result->failed);
        $this->assertSame('Two mistakes', $result->text, 'должен остаться оригинал');
    }

    public function test_budget_stops_translation_of_one_article(): void
    {
        // Бюджет общий на все куски и повторы: без него длинная статья
        // выбирала бы весь таймаут StorePostJob, джоба уходила бы в failed(),
        // и вместо статьи сохранялась заглушка.
        config([
            'translation.gemini.max_chunk_chars' => 60,
            'translation.gemini.budget_seconds' => 0,
        ]);

        Http::fake();

        $source = '<p>'.str_repeat('First. ', 8).'</p><p>'.str_repeat('Second. ', 8).'</p>';

        $result = $this->translator()->translateHtml($source);

        $this->assertTrue($result->failed);
        Http::assertNothingSent();
    }

    public function test_api_error_does_not_throw(): void
    {
        // Регион, исчерпанная квота, отозванный ключ — всё это приходит
        // ошибкой HTTP. Пост важнее перевода: падать здесь нельзя.
        Http::fake([
            '*' => Http::response(
                ['error' => ['message' => 'User location is not supported for the API use.']],
                400
            ),
        ]);

        $result = $this->translator()->translateHtml('<p>Text</p>');

        $this->assertTrue($result->failed);
        $this->assertSame('<p>Text</p>', $result->text);
    }

    public function test_missing_key_is_reported_not_silently_skipped(): void
    {
        config(['translation.gemini.key' => '']);
        Http::fake();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'GEMINI_API_KEY'));

        // Отсутствие ключа повтором не лечится, поэтому движок заодно метит
        // себя нерабочим — иначе каждая следующая статья снова выжидала бы
        // таймаут.
        Log::shouldReceive('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'нерабочим'));

        $result = $this->translator()->translateHtml('<p>Text</p>');

        $this->assertTrue($result->failed);
        Http::assertNothingSent();
    }

    public function test_long_article_is_split_without_breaking_tags(): void
    {
        config(['translation.gemini.max_chunk_chars' => 60]);

        $source = '<p>'.str_repeat('First. ', 8).'</p><p>'.str_repeat('Second. ', 8).'</p>';

        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'];
            $sent[] = $prompt;

            // Перевод соразмерен куску: валидатор отвергает ответы, которые
            // вдвое короче оригинала.
            $fragment = $this->fragmentOf($prompt);

            return Http::response(['candidates' => [[
                'content' => ['parts' => [['text' => str_replace('First.', 'Первое.', str_replace('Second.', 'Второе.', $fragment))]]],
                'finishReason' => 'STOP',
            ]]]);
        });

        $result = $this->translator()->translateHtml($source);

        $this->assertFalse($result->failed);
        $this->assertGreaterThan(1, count($sent), 'длинная статья должна уйти частями');

        // Куски, склеенные обратно, обязаны дать исходник: так ловится и
        // разорванный тег, и потерянный по дороге узел.
        $rejoined = implode('', array_map(fn (string $p) => $this->fragmentOf($p), $sent));
        $this->assertSame($source, $rejoined, 'склейка кусков должна совпадать с оригиналом');
    }

    public function test_partial_failure_does_not_produce_half_russian_article(): void
    {
        config(['translation.gemini.max_chunk_chars' => 60]);

        $source = '<p>'.str_repeat('First. ', 8).'</p><p>'.str_repeat('Second. ', 8).'</p>';

        $call = 0;
        Http::fake(function ($request) use (&$call) {
            $call++;
            $fragment = $this->fragmentOf($request->data()['contents'][0]['parts'][0]['text']);

            return $call === 1
                ? Http::response(['candidates' => [[
                    'content' => ['parts' => [['text' => str_replace('First.', 'Первое.', $fragment)]]],
                    'finishReason' => 'STOP',
                ]]])
                : Http::response(['error' => ['message' => 'boom']], 500);
        });

        $result = $this->translator()->translateHtml($source);

        // Склеить переведённое начало с непереведённым хвостом значит выдать
        // полурусскую статью за готовую.
        $this->assertTrue($result->failed);
        $this->assertSame($source, $result->text);
    }

    public function test_fallback_takes_over_and_is_reported(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $scraper = new class implements Translator
        {
            public function translateHtml(string $html): TranslationResult
            {
                return TranslationResult::success('<p>Скрейпер</p>', 'google');
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::success('Скрейпер', 'google');
            }

            public function name(): string
            {
                return 'google';
            }
        };

        // Тихая деградация — то, на чём проект уже обжёгся с OCR: полгода
        // «нет текста на картинке» вместо «tesseract не установлен».
        Log::shouldReceive('warning')->atLeast()->once();

        $translator = new FallbackTranslator($this->translator(), $scraper);
        $result = $translator->translateHtml('<p>Text</p>');

        $this->assertFalse($result->failed);
        $this->assertSame('google', $result->engine, 'движок должен быть виден вызывающему');
        $this->assertSame('<p>Скрейпер</p>', $result->text);
    }

    public function test_dead_engine_is_not_retried_on_every_article(): void
    {
        // Без паузы пакетная обработка выжидает полный таймаут на КАЖДОЙ
        // статье: сотня постов в очереди — часы простоя воркера ради заведомо
        // провальных запросов.
        config(['translation.circuit_breaker_seconds' => 300]);

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(
                ['error' => ['message' => 'User location is not supported for the API use.']],
                400
            );
        });

        $scraper = new class implements Translator
        {
            public function translateHtml(string $html): TranslationResult
            {
                return TranslationResult::success('<p>Скрейпер</p>', 'google');
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::success('Скрейпер', 'google');
            }

            public function name(): string
            {
                return 'google';
            }
        };

        $translator = new FallbackTranslator($this->translator(), $scraper);

        $translator->translateHtml('<p>First article</p>');
        $translator->translateHtml('<p>Second article</p>');
        $translator->translateHtml('<p>Third article</p>');

        $this->assertSame(1, $attempts, 'после неретраибельной ошибки движок трогать не нужно');
    }

    /**
     * Достаёт из промпта то, что отдано модели на перевод.
     */
    private function fragmentOf(string $prompt): string
    {
        preg_match('/<<<НАЧАЛО ФРАГМЕНТА>>>\s*(.*?)\s*<<<КОНЕЦ ФРАГМЕНТА>>>/su', $prompt, $m);

        return $m[1] ?? '';
    }

    private function translator(): GeminiTranslator
    {
        return new GeminiTranslator(app(HttpFactory::class), new TranslatedHtmlValidator);
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

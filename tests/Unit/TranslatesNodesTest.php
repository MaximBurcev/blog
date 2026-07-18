<?php

namespace Tests\Unit;

use App\Traits\TranslatesNodes;
use DOMDocument;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Tests\TestCase;

/**
 * Тесты на TranslatesNodes — поблочный перевод DOM-контента.
 *
 * Переводчик подменён стабом: mb_strtoupper() имитирует перевод
 * (плейсхолдеры ${n} при этом не искажаются, как и у реального
 * preserveParameters), throwTimes/forcedResult моделируют сбои Google.
 */
class TranslatesNodesTest extends TestCase
{
    public function test_paragraph_translated_as_single_block_with_inline_tags_preserved(): void
    {
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<p>Use <strong>Laravel</strong> to build apps</p>'
        );

        // Пробел внутри <strong> добавлен намеренно (см. maskMarkup) —
        // защищает слово от склейки с плейсхолдером у Google Translate
        $this->assertSame('<p>USE <strong> LARAVEL </strong> TO BUILD APPS</p>', $result);

        // Один блок = один запрос к переводчику
        $this->assertCount(1, $translator->calls);

        // В переводчик ушли плейсхолдеры, а не HTML-теги
        $this->assertStringContainsString('${0}', $translator->calls[0]);
        $this->assertStringNotContainsString('<', $translator->calls[0]);
    }

    public function test_spacing_around_inline_tags_is_preserved(): void
    {
        $result = $this->harness(new FakeGoogleTranslate)->translateHtml(
            '<p>Text with <a href="https://x.com/a?b=1&amp;c=2">link</a> inside</p>'
        );

        // Пробелы вокруг инлайн-тега не потеряны (раньше Google их триммил
        // при поузловом переводе и слова слипались)
        $this->assertStringContainsString('WITH <a', $result);
        $this->assertStringContainsString('</a> INSIDE', $result);

        // Атрибуты ссылки не тронуты
        $this->assertStringContainsString('href="https://x.com/a?b=1&amp;c=2"', $result);
    }

    public function test_code_content_is_never_sent_to_translator(): void
    {
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<p>Run <code>composer install --no-dev</code> now</p>'
        );

        $this->assertStringContainsString('<code>composer install --no-dev</code>', $result);
        $this->assertStringContainsString('RUN', $result);

        foreach ($translator->calls as $call) {
            $this->assertStringNotContainsString('composer', $call);
        }
    }

    public function test_code_styled_as_rhetorical_phrase_is_translated(): void
    {
        // Некоторые авторы оформляют риторические фразы через <code> вместо
        // <em> (см. реальный пример: christoph-rumpel.com/the-agentic-artisan,
        // "<code>which ones, and how?</code>") — это не код, это акцент
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<p>The question is: <code>which ones, and how?</code></p>'
        );

        $this->assertStringContainsString('WHICH ONES, AND HOW?', $result);
        $this->assertStringContainsString('which ones, and how?', $translator->calls[0]);
    }

    public function test_single_word_code_without_punctuation_is_translated(): void
    {
        // Реальный пример (christoph-rumpel.com/the-agentic-artisan): список
        // "то же, что всегда делало вас ценным" — каждый пункт одно слово
        // без пунктуации в <code>. Прежняя версия эвристики (нужна точка/
        // 3+ слова) это упускала
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<li>Your <code>ideas</code></li>'
        );

        // Пробел внутри <code> добавлен намеренно (см. maskMarkup)
        $this->assertStringContainsString('<code> IDEAS </code>', $result);
        $this->assertStringContainsString('ideas', $translator->calls[0]);
    }

    public function test_cli_command_with_colon_still_treated_as_code(): void
    {
        // Двоеточие — характерный признак artisan-команд (migrate:fresh,
        // queue:work) и т.п.; не должно классифицироваться как проза
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<p>Run <code>migrate:fresh</code> now</p>'
        );

        $this->assertStringContainsString('<code>migrate:fresh</code>', $result);
        foreach ($translator->calls as $call) {
            $this->assertStringNotContainsString('migrate', $call);
        }
    }

    public function test_camel_case_identifier_still_treated_as_code(): void
    {
        // "UserController" — похоже на реальный идентификатор (PascalCase),
        // не на слово естественного языка
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<p>See <code>UserController</code> for details</p>'
        );

        $this->assertStringContainsString('<code>UserController</code>', $result);
        foreach ($translator->calls as $call) {
            $this->assertStringNotContainsString('UserController', $call);
        }
    }

    public function test_word_glued_to_inline_tag_without_space_is_not_dropped_by_translator(): void
    {
        // Регрессия на реальный баг: Google Translate, получив плейсхолдер
        // вплотную к слову без пробела ("knows ${0}current${1} Laravel"),
        // трактует их как один непереводимый кусок и оставляет слово как
        // есть. Стаб-переводчик ниже воспроизводит именно это поведение
        // Google, чтобы доказать, что фикс (пробел вокруг токена) работает.
        $translator = new GluingFakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<p>it knows <em>current</em> Laravel</p>'
        );

        $this->assertStringContainsString('ПЕРЕВЕДЕНО', $result);
        $this->assertStringNotContainsString('current', $result);
    }

    public function test_pre_block_is_untouched_and_costs_zero_requests(): void
    {
        $translator = new FakeGoogleTranslate;
        $html = '<pre><code>$foo = 1;</code></pre>';

        $result = $this->harness($translator)->translateHtml($html);

        $this->assertSame($html, $result);
        $this->assertCount(0, $translator->calls);
    }

    public function test_each_list_item_is_translated_as_own_block(): void
    {
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<ul><li>First</li><li>Second <em>item</em></li></ul>'
        );

        $this->assertSame('<ul><li>FIRST</li><li>SECOND <em> ITEM </em></li></ul>', $result);
        $this->assertCount(2, $translator->calls);
    }

    public function test_block_with_nested_containers_recurses_instead_of_single_request(): void
    {
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<blockquote><p>Quoted text</p></blockquote>'
        );

        $this->assertSame('<blockquote><p>QUOTED TEXT</p></blockquote>', $result);
        $this->assertCount(1, $translator->calls);
    }

    public function test_bare_text_in_container_falls_back_to_per_node_translation(): void
    {
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<div>hello <span>world</span></div>'
        );

        $this->assertStringContainsString('HELLO', $result);
        $this->assertStringContainsString('<span>WORLD</span>', $result);
    }

    public function test_empty_translation_keeps_original_block(): void
    {
        $translator = new FakeGoogleTranslate;
        $translator->forcedResult = '';
        $html = '<p>Some text</p>';

        $result = $this->harness($translator)->translateHtml($html);

        $this->assertSame($html, $result);
        // translateWithFallback делает 2 попытки
        $this->assertCount(2, $translator->calls);
    }

    public function test_translator_exception_keeps_original_block(): void
    {
        $translator = new FakeGoogleTranslate;
        $translator->throwTimes = 2;
        $html = '<p>Some text</p>';

        $result = $this->harness($translator)->translateHtml($html);

        $this->assertSame($html, $result);
    }

    public function test_lost_placeholder_keeps_original_block_to_avoid_broken_markup(): void
    {
        $translator = new FakeGoogleTranslate;
        // Переводчик «потерял» плейсхолдеры — разметку восстановить нельзя
        $translator->forcedResult = 'ПЕРЕВОД БЕЗ ПЛЕЙСХОЛДЕРОВ';
        $html = '<p>Use <strong>Laravel</strong> now</p>';

        $result = $this->harness($translator)->translateHtml($html);

        $this->assertSame($html, $result);
    }

    public function test_mangled_service_tokens_from_google_are_normalized(): void
    {
        $translator = new FakeGoogleTranslate;
        // Google вернул токены с пробелами (#{0} → # {0}) — библиотека такие
        // не восстанавливает, трейт должен донормализовать их сам
        $translator->forcedResult = 'ЗАПУСТИ # {0}СЕЙЧАС#{ 1 } ЖЕ';

        $result = $this->harness($translator)->translateHtml(
            '<p>Run <strong>now</strong> please</p>'
        );

        $this->assertSame('<p>ЗАПУСТИ <strong>СЕЙЧАС</strong> ЖЕ</p>', $result);
    }

    public function test_mangled_number_sign_token_from_google_is_normalized(): void
    {
        // Регрессия на реальный баг: Google при переводе на русский иногда
        // подменяет "$" на "№" (особенно в ПОСЛЕДНЕМ токене строки) —
        // воспроизведено стабильно на реальном API: "...how? ${3}" →
        // "...как? №{3}"
        $translator = new FakeGoogleTranslate;
        $translator->forcedResult = 'ЗАПУСТИ ${0}СЕЙЧАС№{1} ЖЕ';

        $result = $this->harness($translator)->translateHtml(
            '<p>Run <strong>now</strong> please</p>'
        );

        $this->assertSame('<p>ЗАПУСТИ <strong>СЕЙЧАС</strong> ЖЕ</p>', $result);
    }

    public function test_block_with_only_markup_costs_zero_requests(): void
    {
        $translator = new FakeGoogleTranslate;
        $html = '<p><img src="/img/x.png" alt=""></p>';

        $this->harness($translator)->translateHtml($html);

        $this->assertCount(0, $translator->calls);
    }

    public function test_html_entities_are_decoded_for_translator_and_reencoded_after(): void
    {
        $translator = new FakeGoogleTranslate;
        $result = $this->harness($translator)->translateHtml(
            '<p>Tom &amp; Jerry</p>'
        );

        // Переводчик видит живой символ, а не entity
        $this->assertStringContainsString('Tom & Jerry', $translator->calls[0]);
        // В итоговом HTML амперсанд снова закодирован
        $this->assertSame('<p>TOM &amp; JERRY</p>', $result);
    }

    public function test_empty_tag_left_by_word_reordering_is_removed(): void
    {
        // Регрессия на реальный баг: Google при переводе иногда переставляет
        // короткое слово из середины инлайн-тега наружу (грамматический
        // порядок в русском), парный тег остаётся пустой оболочкой —
        // воспроизведено на реальном API: "Your <code>strategy</code>" →
        // "Ваша стратегия ${0} ${1}" (слово уехало наружу, тег опустел)
        $translator = new FakeGoogleTranslate;
        $translator->forcedResult = 'Ваша стратегия ${0} ${1}';

        $result = $this->harness($translator)->translateHtml(
            '<ul><li>Your <code>strategy</code></li></ul>'
        );

        // Хвостовой пробел (от места удалённого пустого тега) визуально
        // не важен для инлайн-разметки
        $this->assertSame('<ul><li>Ваша стратегия </li></ul>', $result);
    }

    public function test_successful_translation_reports_no_fallbacks(): void
    {
        $harness = $this->harness(new FakeGoogleTranslate);
        $harness->translateHtml('<p>Use <strong>Laravel</strong> now</p>');

        $this->assertFalse($harness->hadFallbacks());
    }

    public function test_empty_translation_is_counted_as_fallback(): void
    {
        $translator = new FakeGoogleTranslate;
        $translator->forcedResult = '';

        $harness = $this->harness($translator);
        $harness->translateHtml('<p>Some text</p>');

        $this->assertTrue($harness->hadFallbacks());
    }

    public function test_lost_placeholder_is_counted_as_fallback(): void
    {
        $translator = new FakeGoogleTranslate;
        $translator->forcedResult = 'ПЕРЕВОД БЕЗ ПЛЕЙСХОЛДЕРОВ';

        $harness = $this->harness($translator);
        $harness->translateHtml('<p>Use <strong>Laravel</strong> now</p>');

        $this->assertTrue($harness->hadFallbacks());
    }

    private function harness(GoogleTranslate $translator): TranslatesNodesHarness
    {
        return new TranslatesNodesHarness($translator);
    }
}

/**
 * Хост трейта: гоняет processNode по детям <body> и сериализует результат.
 */
class TranslatesNodesHarness
{
    use TranslatesNodes;

    public function __construct(private readonly GoogleTranslate $googleTranslate) {}

    public function hadFallbacks(): bool
    {
        return $this->hasTranslationFallbacks();
    }

    public function translateHtml(string $html): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>');

        $body = $dom->getElementsByTagName('body')->item(0);

        foreach (iterator_to_array($body->childNodes) as $child) {
            $this->processNode($child);
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }
}

class FakeGoogleTranslate extends GoogleTranslate
{
    /** @var string[] */
    public array $calls = [];

    public ?string $forcedResult = null;

    public int $throwTimes = 0;

    public function __construct()
    {
        parent::__construct('ru');
    }

    public function translate(string $string): ?string
    {
        $this->calls[] = $string;

        if ($this->throwTimes > 0) {
            $this->throwTimes--;
            throw new \RuntimeException('translator unavailable');
        }

        if ($this->forcedResult !== null) {
            return $this->forcedResult;
        }

        // mb_strtoupper не искажает ${n} — имитирует perfect-перевод
        return mb_strtoupper($string);
    }
}

/**
 * Имитирует реальное поведение Google Translate: слово, зажатое между
 * двумя плейсхолдерами БЕЗ пробела (напр. "${0}current${1}"), трактуется
 * как единый непереводимый кусок и остаётся как есть; остальной текст
 * переводится нормально.
 */
class GluingFakeGoogleTranslate extends GoogleTranslate
{
    public function __construct()
    {
        parent::__construct('ru');
    }

    public function translate(string $string): ?string
    {
        // Слово вплотную к токену с обеих сторон - не переводим (баг Google)
        if (preg_match('/\$\{\d+\}\S+\$\{\d+\}/', $string)) {
            return $string;
        }

        return preg_replace('/[^\$\{\}\d\s]+/u', 'ПЕРЕВЕДЕНО', $string);
    }
}

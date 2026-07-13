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

        $this->assertSame('<p>USE <strong>LARAVEL</strong> TO BUILD APPS</p>', $result);

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

        $this->assertSame('<ul><li>FIRST</li><li>SECOND <em>ITEM</em></li></ul>', $result);
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

    private function harness(FakeGoogleTranslate $translator): TranslatesNodesHarness
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

    public function __construct(private readonly FakeGoogleTranslate $googleTranslate) {}

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

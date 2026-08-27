<?php

namespace Tests\Feature;

use App\Models\Tool;
use App\Service\ReleaseService;
use App\Service\ToolImportService;
use App\Service\Translation\TranslationResult;
use App\Service\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_are_imported_from_their_section(): void
    {
        $stats = $this->import($this->translatingTranslator());

        $this->assertSame(3, $stats['created']);

        $tool = Tool::where('name', 'cerbero/json-parser')->sole();
        $this->assertSame('https://github.com/cerbero90/json-parser', $tool->url);
        $this->assertSame('Zero-dependencies pull parser to read large JSON.', $tool->description_orig);
        $this->assertTrue($tool->is_published);
    }

    public function test_short_descriptions_are_not_dropped(): void
    {
        $this->import($this->translatingTranslator());

        $this->assertTrue(
            Tool::where('name', 'yajra/laravel-datatables-html')->exists(),
            'пакет с описанием короче новостного порога обязан импортироваться'
        );
    }

    public function test_all_descriptions_are_translated_in_a_single_request(): void
    {
        $translator = $this->translatingTranslator();

        $stats = $this->import($translator);

        $this->assertCount(1, $translator->calls, 'на выпуск должен уходить один запрос к модели');
        $this->assertSame(3, $stats['translated']);

        $this->assertSame(
            'ПЕРЕВОД: Zero-dependencies pull parser to read large JSON.',
            Tool::where('name', 'cerbero/json-parser')->sole()->description,
        );
        $this->assertSame(
            'ПЕРЕВОД: Laravel DataTables HTML builder plugin.',
            Tool::where('name', 'yajra/laravel-datatables-html')->sole()->description,
        );
    }

    public function test_failed_translation_keeps_the_english_original(): void
    {
        $stats = $this->import($this->failingTranslator());

        $this->assertSame(3, $stats['created']);
        $this->assertSame(0, $stats['translated']);

        $tool = Tool::where('name', 'cerbero/json-parser')->sole();
        $this->assertNull($tool->description);
        $this->assertSame('Zero-dependencies pull parser to read large JSON.', $tool->description_orig);
        $this->assertNull($tool->translated_by);
        $this->assertSame('Zero-dependencies pull parser to read large JSON.', $tool->displayDescription());
    }

    public function test_translation_with_a_different_item_count_is_discarded(): void
    {
        $stats = $this->import($this->manglingTranslator());

        $this->assertSame(3, $stats['created']);
        $this->assertSame(0, $stats['translated']);
        $this->assertSame(0, Tool::whereNotNull('description')->count());
    }

    public function test_engine_returning_the_original_text_does_not_count_as_translation(): void
    {
        $stats = $this->import($this->echoingTranslator());

        $this->assertSame(3, $stats['created']);
        $this->assertSame(0, $stats['translated'], 'неизменённый текст переводом не считается');
        $this->assertSame(0, Tool::whereNotNull('description')->count());
        $this->assertSame(0, Tool::whereNotNull('translated_by')->count());
    }

    public function test_already_imported_tools_are_skipped(): void
    {
        $this->import($this->translatingTranslator());
        $stats = $this->import($this->translatingTranslator());

        $this->assertSame(0, $stats['created']);
        $this->assertSame(3, $stats['skipped']);
        $this->assertSame(3, Tool::count());
    }

    public function test_only_its_own_section_is_imported(): void
    {
        $this->import($this->translatingTranslator());

        $this->assertFalse(
            Tool::where('url', 'https://laravel-news.com/laravel-13-25-0')->exists(),
            'ссылка из новостной секции не должна попадать в инструменты'
        );
    }

    public function test_batch_failure_falls_back_to_per_item_translation(): void
    {
        $translator = $this->htmlBlindTranslator();

        $stats = $this->import($translator);

        $this->assertSame(3, $stats['translated'], 'поштучный проход обязан подхватить всю пачку');
        $this->assertSame(
            'ПОШТУЧНО: Zero-dependencies pull parser to read large JSON.',
            Tool::where('name', 'cerbero/json-parser')->sole()->description,
        );
    }

    public function test_pending_descriptions_are_translated_later(): void
    {
        $this->import($this->failingTranslator());
        $this->assertSame(3, Tool::whereNull('description')->count());

        $stats = $this->service($this->translatingTranslator())->translatePending();

        $this->assertSame(3, $stats['found']);
        $this->assertSame(3, $stats['translated']);
        $this->assertSame(0, Tool::whereNull('description')->count());

        $tool = Tool::where('name', 'mnapoli/simple-s3')->sole();
        $this->assertSame('ПЕРЕВОД: Simple, single-file and dependency-free AWS S3 client.', $tool->description);
        $this->assertSame('spy', $tool->translated_by);
    }

    public function test_translate_pending_does_nothing_when_everything_is_translated(): void
    {
        $this->import($this->translatingTranslator());

        $stats = $this->service($this->translatingTranslator())->translatePending();

        $this->assertSame(0, $stats['found']);
    }

    public function test_deleted_tool_is_not_resurrected_by_import(): void
    {
        $this->import($this->translatingTranslator());
        Tool::where('name', 'mnapoli/simple-s3')->sole()->delete();

        $stats = $this->import($this->translatingTranslator());

        $this->assertSame(0, $stats['created']);
        $this->assertSame(3, $stats['skipped']);
        $this->assertSame(2, Tool::count());
    }

    public function test_missing_section_imports_nothing(): void
    {
        $stats = $this->import($this->translatingTranslator(), digest: '<html><body><p>ничего</p></body></html>');

        $this->assertSame(0, $stats['found']);
        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, Tool::count());
    }

    private function service(Translator $translator, ?string $digest = null): ToolImportService
    {
        $releases = new class($digest ?? $this->digest()) extends ReleaseService
        {
            public function __construct(private readonly string $digest)
            {
                parent::__construct();
            }

            public function fetchHtmlContent(string $url): string
            {
                return $this->digest;
            }
        };

        return new ToolImportService($translator, $releases);
    }

    /**
     * @return array{found: int, created: int, skipped: int, rejected: int, translated: int}
     */
    private function import(Translator $translator, ?string $digest = null): array
    {
        return $this->service($translator, $digest)->importFromDigest('https://example.test/digest');
    }

    private function digest(): string
    {
        return '<html><body><table>'
            .'<tr><td class="bodyContent"><h2>News and Announcements</h2><br>'
            .'<a href="https://laravel-news.com/laravel-13-25-0">Pause All Queues in Laravel 13.25</a><br>'
            .'Laravel 13.25 adds a global pause switch that stops every queue on every connection.<br><br>'
            .'</td></tr>'
            .'<tr><td class="bodyContent"><h2>Interesting Projects, Tools and Libraries</h2><br>'
            .'<a href="https://github.com/yajra/laravel-datatables-html">yajra/laravel-datatables-html</a><br>'
            .'Laravel DataTables HTML builder plugin.<br><br>'
            .'<a href="https://github.com/cerbero90/json-parser">cerbero/json-parser</a><br>'
            .'Zero-dependencies pull parser to read large JSON.<br><br>'
            .'<a href="https://github.com/mnapoli/simple-s3">mnapoli/simple-s3</a><br>'
            .'Simple, single-file and dependency-free AWS S3 client.<br>'
            .'</td></tr>'
            .'</table></body></html>';
    }

    private function translatingTranslator(): Translator
    {
        return new class implements Translator
        {
            /** @var string[] */
            public array $calls = [];

            public function name(): string
            {
                return 'spy';
            }

            public function translateHtml(string $html): TranslationResult
            {
                $this->calls[] = $html;

                return TranslationResult::success(
                    (string) preg_replace('~<li>~', '<li>ПЕРЕВОД: ', $html),
                    $this->name()
                );
            }

            public function translateText(string $text): TranslationResult
            {
                $this->calls[] = $text;

                return TranslationResult::success('ПЕРЕВОД: '.$text, $this->name());
            }
        };
    }

    private function failingTranslator(): Translator
    {
        return new class implements Translator
        {
            public function name(): string
            {
                return 'spy';
            }

            public function translateHtml(string $html): TranslationResult
            {
                return TranslationResult::failure($html);
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::failure($text);
            }
        };
    }

    private function htmlBlindTranslator(): Translator
    {
        return new class implements Translator
        {
            public function name(): string
            {
                return 'google';
            }

            public function translateHtml(string $html): TranslationResult
            {
                return new TranslationResult($html, $this->name(), failed: false, partial: true);
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::success('ПОШТУЧНО: '.$text, $this->name());
            }
        };
    }

    private function echoingTranslator(): Translator
    {
        return new class implements Translator
        {
            public function name(): string
            {
                return 'google';
            }

            public function translateHtml(string $html): TranslationResult
            {
                return TranslationResult::success($html, $this->name());
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::success($text, $this->name());
            }
        };
    }

    private function manglingTranslator(): Translator
    {
        return new class implements Translator
        {
            public function name(): string
            {
                return 'spy';
            }

            public function translateHtml(string $html): TranslationResult
            {
                return TranslationResult::success('<ul><li>Единственный пункт.</li></ul>', $this->name());
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::success($text, $this->name());
            }
        };
    }
}

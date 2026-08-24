<?php

namespace Tests\Feature;

use App\Jobs\StorePostJob;
use App\Models\Post;
use App\Service\NewsImportService;
use App\Service\ReleaseService;
use App\Service\Translation\TranslationResult;
use App\Service\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Холостой ход новостного импорта.
 *
 * Инцидент 24.08.2026: из 11 вызовов модели за сутки ВСЕ 11 пришлись на
 * заглушки — ролики YouTube, главную php.net и страницы релизов GitHub,
 * которые импорт перезапускал каждое утро. 55% бесплатной суточной квоты
 * уходило на страницы, где статьи не существует, и это же съедало половину
 * лимита до того, как очередь доходила до живых статей.
 *
 * Складывались две вещи, и тесты здесь про обе: разбор платил модели за
 * перевод заголовка ДО того, как выяснится, что тела статьи нет, а повторы
 * заглушек не имели потолка.
 */
class NewsImportWasteTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_without_article_body_does_not_reach_the_model(): void
    {
        // Страница отдаёт заголовок, но тела статьи по селектору нет — ровно
        // случай ролика YouTube. Перевод такого заголовка оплачен, а увидит
        // его только заглушка в админке.
        $translator = $this->spyingTranslator();

        $this->runJobOn(
            '<html><head><title>Some Video</title></head><body><h1>Some Video</h1>'
            .'<div class="nothing-useful">no article here</div></body></html>',
            $translator,
        );

        $this->assertSame([], $translator->calls, 'модель не должна вызываться для страницы без статьи');

        $post = Post::sole();
        $this->assertSame(Post::PARSE_STATUS_FAILED, $post->parse_status);

        // Заглушка не остаётся безымянной — просто заголовок непереведённый.
        $this->assertSame('Some Video', $post->title);
    }

    public function test_page_with_article_body_still_gets_its_title_translated(): void
    {
        // Обратная половина: перестановка не должна отменить перевод там, где
        // он по-прежнему нужен.
        $translator = $this->spyingTranslator();

        $this->runJobOn(
            '<html><head><title>Real Article</title></head><body><h1>Real Article</h1>'
            .'<div class="post-content"><p>Some real body text here.</p></div></body></html>',
            $translator,
            selector: '.post-content',
        );

        $this->assertContains('Real Article', $translator->calls, 'заголовок живой статьи обязан переводиться');

        $post = Post::sole();
        $this->assertSame(Post::PARSE_STATUS_OK, $post->parse_status);
        $this->assertSame('ПЕРЕВОД: Real Article', $post->title);
    }

    public function test_reparse_without_a_working_translator_does_not_destroy_the_translation(): void
    {
        // Инцидент 24.08.2026, пост 236. У модели кончилась суточная квота,
        // скрейпер получил 429 на каждом блоке 44-килобайтной статьи — и
        // поверх полного русского текста лёг английский оригинал. Следом
        // осталась одна галочка «перевод неполный»; спасли ночные бэкапы.
        $url = 'https://wendelladriel.com/blog/immutability-in-php-beyond-readonly';

        $translated = Post::withoutSyncingToSearch(fn (): Post => Post::create([
            'title' => 'Неизменяемость в PHP за пределами readonly',
            'code' => 'neizmenyaemost-v-php',
            'content' => '<p>Ключевое слово readonly делает код безопаснее и понятнее людям.</p>',
            'content_orig' => '<p>The readonly keyword makes code safer.</p>',
            'url' => $url,
            'translated_by' => 'google',
            'parse_status' => Post::PARSE_STATUS_OK,
        ]));

        // Оба движка лежат: перевода не будет, вернётся оригинал.
        $this->runJobOn(
            '<html><head><title>Immutability in PHP</title></head><body><h1>Immutability in PHP</h1>'
            .'<div class="post-content"><p>The readonly keyword makes code safer.</p></div></body></html>',
            $this->deadTranslator(),
            selector: '.post-content',
            url: $url,
        );

        $translated->refresh();

        $this->assertStringContainsString('Ключевое слово readonly', $translated->content, 'перевод затёрли');
        $this->assertSame('Неизменяемость в PHP за пределами readonly', $translated->title);
        $this->assertSame('google', $translated->translated_by);
    }

    public function test_reparse_with_a_working_translator_still_updates_the_post(): void
    {
        // Обратная половина: защита не должна превратиться в «перепарсинг
        // больше ничего не меняет».
        $url = 'https://example.test/article';

        $post = Post::withoutSyncingToSearch(fn (): Post => Post::create([
            'title' => 'Старый заголовок',
            'code' => 'staryj',
            'content' => '<p>Короткий старый перевод текста.</p>',
            'url' => $url,
            'translated_by' => 'google',
            'parse_status' => Post::PARSE_STATUS_OK,
        ]));

        $this->runJobOn(
            '<html><head><title>New</title></head><body><h1>New</h1>'
            .'<div class="post-content"><p>Fresh English body.</p></div></body></html>',
            $this->cyrillicTranslator(),
            selector: '.post-content',
            url: $url,
        );

        $post->refresh();

        $this->assertStringContainsString('Свежий перевод', $post->content);
        $this->assertSame('ПЕРЕВОД: New', $post->title);
    }

    public function test_engine_is_recorded_by_result_not_by_who_tried(): void
    {
        // В базе стоял translated_by = 'google' при нуле кириллицы: скрейпер
        // вернул все блоки как есть и отчитался частичным успехом. По этому
        // признаку выбирают, что переводить заново, — врать он не должен.
        $this->runJobOn(
            '<html><head><title>Untranslated</title></head><body><h1>Untranslated</h1>'
            .'<div class="post-content"><p>Nothing was translated here.</p></div></body></html>',
            $this->deadTranslator(),
            selector: '.post-content',
        );

        $post = Post::sole();

        $this->assertSame(TranslationResult::NO_ENGINE, $post->translated_by);
        $this->assertTrue((bool) $post->translation_incomplete);
    }

    public function test_import_gives_up_on_a_stub_after_the_retry_limit(): void
    {
        Queue::fake();
        config(['releases.news_retry_limit' => 3]);

        $stub = $this->failedStub('https://www.youtube.com/watch?v=zombie');

        for ($i = 1; $i <= 3; $i++) {
            $stats = $this->import();
            $this->assertSame(1, $stats['dispatched'], "попытка {$i} должна состояться");
            $this->assertSame($i, $stub->refresh()->parse_attempts);
        }

        // Четвёртого утра не будет.
        $stats = $this->import();

        $this->assertSame(0, $stats['dispatched']);
        $this->assertSame(1, $stats['exhausted'], 'отказ обязан быть назван вслух, а не слит в «пропущено»');
        $this->assertSame(3, $stub->refresh()->parse_attempts, 'исчерпанная заглушка счётчик больше не крутит');

        // Сколько задач реально легло в очередь, здесь не проверяем: у
        // StorePostJob есть uniqueId(), и три диспатча подряд без выполнения
        // схлопываются в один — блокировка снимается, только когда задача
        // отработает. В жизни между попытками сутки, так что схлопывания нет,
        // а в тесте оно измеряло бы поведение Laravel, а не наше решение
        // «повторять или сдаться».
        Queue::assertPushed(StorePostJob::class);
    }

    public function test_successfully_parsed_post_is_never_retried_and_never_counted(): void
    {
        Queue::fake();

        $post = $this->failedStub('https://www.youtube.com/watch?v=zombie');
        $post->update(['parse_status' => Post::PARSE_STATUS_OK]);

        $stats = $this->import();

        $this->assertSame(0, $stats['dispatched']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, $stats['exhausted']);
        // Счётчик не трогаем: до него дело не доходит, поэтому и сбрасывать
        // его нигде не нужно.
        $this->assertSame(0, $post->refresh()->parse_attempts);
    }

    public function test_zero_limit_means_no_ceiling_not_no_retries(): void
    {
        Queue::fake();
        config(['releases.news_retry_limit' => 0]);

        $stub = $this->failedStub('https://www.youtube.com/watch?v=zombie');
        // forceFill, а не update: parse_attempts намеренно вне $fillable, и
        // update() молча ничего бы не записал — тест проверял бы ветку «0
        // попыток» вместо заявленной «попыток больше лимита».
        $stub->forceFill(['parse_attempts' => 99])->save();

        $stats = $this->import();

        $this->assertSame(1, $stats['dispatched'], '0 отключает потолок, а не повторы');
    }

    /**
     * Прогоняет джобу на готовом HTML с подменённым переводчиком.
     */
    private function runJobOn(string $html, Translator $translator, string $selector = '', string $url = ''): void
    {
        $this->app->instance(Translator::class, $translator);

        $dir = sys_get_temp_dir().'/newsimport_'.uniqid();
        mkdir($dir);
        config(['releases.html_import_dir' => $dir]);
        $file = $dir.'/article.html';
        file_put_contents($file, $html);

        try {
            Post::withoutSyncingToSearch(function () use ($file, $selector, $url) {
                $this->app->call([new StorePostJob([
                    'url' => $url,
                    'html_file' => $file,
                    'selector' => $selector,
                ]), 'handle']);
            });
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * Оба движка легли: перевода не будет, вернётся исходный текст. Именно так
     * повёл себя скрейпер, получив 429 на каждом блоке.
     */
    private function deadTranslator(): Translator
    {
        return new class implements Translator
        {
            public function name(): string
            {
                return 'google';
            }

            public function translateHtml(string $html): TranslationResult
            {
                return new TranslationResult($html, $this->name(), partial: true);
            }

            public function translateText(string $text): TranslationResult
            {
                return new TranslationResult($text, $this->name(), partial: true);
            }
        };
    }

    private function cyrillicTranslator(): Translator
    {
        return new class implements Translator
        {
            public function name(): string
            {
                return 'gemini';
            }

            public function translateHtml(string $html): TranslationResult
            {
                return TranslationResult::success('<p>Свежий перевод статьи целиком.</p>', $this->name());
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::success('ПЕРЕВОД: '.$text, $this->name());
            }
        };
    }

    /**
     * Импорт с подменённым скачиванием дайджеста: сеть тут ни при чём,
     * проверяется решение «повторять или сдаться».
     *
     * @return array{dispatched: int, skipped: int, exhausted: int}
     */
    private function import(string $url = 'https://www.youtube.com/watch?v=zombie'): array
    {
        $digest = '<html><body><table><tr><td class="bodyContent">'
            .'<h2>News and Announcements</h2><br>'
            .'<a href="'.$url.'">Zombie</a><br>'
            .'Описание достаточной длины, чтобы парсер не счёл его мусором.<br><br>'
            .'</td></tr></table></body></html>';

        $releases = new class($digest) extends ReleaseService
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

        return (new NewsImportService($releases))->importFromDigest('https://example.test/digest');
    }

    private function failedStub(string $url): Post
    {
        return Post::withoutSyncingToSearch(fn (): Post => Post::create([
            'title' => 'Zombie',
            'code' => 'zombie-'.uniqid(),
            'content' => '',
            'url' => $url,
            'is_news' => true,
            'parse_status' => Post::PARSE_STATUS_FAILED,
        ]));
    }

    /**
     * Переводчик, который записывает, о чём его просили. Именно факт вызова —
     * предмет проверки: обычный fake ответил бы и остался незамеченным.
     */
    private function spyingTranslator(): Translator
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

                return TranslationResult::success($html, $this->name());
            }

            public function translateText(string $text): TranslationResult
            {
                $this->calls[] = $text;

                return TranslationResult::success('ПЕРЕВОД: '.$text, $this->name());
            }
        };
    }
}

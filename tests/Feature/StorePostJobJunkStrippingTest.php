<?php

namespace Tests\Feature;

use App\Events\PostCreated;
use App\Jobs\StorePostJob;
use App\Models\Post;
use App\Service\Translation\TranslationResult;
use App\Service\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Регрессия на пост 331 (evilmartians.com, 09.09.2026): фреймворк страницы
 * сериализует все пропсы в атрибут props — 82 КБ JSON из 109 КБ статьи.
 * Переводчик получал ~60K входных токенов при 3,4K текста и ответ обрывался
 * по max_output_tokens, джоба не укладывалась в таймаут, пост оставался
 * заглушкой. props не контент никогда — атрибут вырезается до перевода.
 */
class StorePostJobJunkStrippingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Переводчик-пустышка: возвращает вход как есть, сети нет. Джобе важен
     * не перевод, а то, какой HTML до него доезжает.
     */
    private function fakeTranslator(): void
    {
        $this->app->instance(Translator::class, new class implements Translator
        {
            public function translateHtml(string $html): TranslationResult
            {
                return TranslationResult::success($html, $this->name());
            }

            public function translateText(string $text): TranslationResult
            {
                return TranslationResult::success($text, $this->name());
            }

            public function name(): string
            {
                return 'fake';
            }
        });
    }

    public function test_serialized_props_attribute_is_stripped_before_translation(): void
    {
        Event::fake([PostCreated::class]);
        $this->fakeTranslator();

        $payload = str_repeat('{"slug":"x","body":"Lorem ipsum dolor sit amet"}', 2000);
        $html = '<html><head><title>Props Junk</title></head><body>'
            .'<h1>Props Junk</h1>'
            .'<article props="'.htmlspecialchars($payload, ENT_QUOTES).'">'
            .'<p>Real article text</p>'
            .'</article></body></html>';

        $dir = sys_get_temp_dir().'/storepostjob_imports_'.uniqid();
        mkdir($dir);
        config(['releases.html_import_dir' => $dir]);
        $file = $dir.'/article.html';
        file_put_contents($file, $html);

        try {
            Post::withoutSyncingToSearch(function () use ($file) {
                $this->app->call([new StorePostJob([
                    'url' => '',
                    'html_file' => $file,
                    'selector' => 'article',
                ]), 'handle']);
            });
        } finally {
            @unlink($file);
            @rmdir($dir);
        }

        $post = Post::sole();
        $this->assertStringContainsString('Real article text', (string) $post->content);
        $this->assertStringNotContainsString('props=', (string) $post->content);
        $this->assertStringNotContainsString('Lorem ipsum dolor sit amet', (string) $post->content);
    }
}

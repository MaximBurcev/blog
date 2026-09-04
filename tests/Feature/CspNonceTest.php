<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: script-src держал 'unsafe-inline' и
 * 'unsafe-eval', то есть CSP не мешала исполнить внедрённый в разметку скрипт
 * — а это ровно тот вектор, ради которого политику и ставят. Своих инлайнов
 * на публичной части всего четыре, и все они теперь ходят с nonce.
 */
class CspNonceTest extends TestCase
{
    use RefreshDatabase;

    private function scriptSrc(string $header): string
    {
        foreach (explode(';', $header) as $directive) {
            if (str_starts_with(trim($directive), 'script-src')) {
                return trim($directive);
            }
        }

        return '';
    }

    public function test_public_page_uses_nonce_instead_of_unsafe_inline(): void
    {
        $response = $this->get('/');

        $scriptSrc = $this->scriptSrc($response->headers->get('Content-Security-Policy'));

        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\/=]+'/", $scriptSrc);
    }

    public function test_every_inline_script_carries_the_header_nonce(): void
    {
        $post = Post::withoutSyncingToSearch(function () {
            $category = Category::create(['title' => 'Laravel', 'code' => 'laravel']);

            return Post::create([
                'title' => 'Пост',
                'code' => 'post',
                'content' => '<p>текст</p>',
                'published' => true,
                'category_id' => $category->id,
            ]);
        });

        $response = $this->get(route('post.show', $post->code));
        $response->assertOk();

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $matches);
        $nonce = $matches[1] ?? null;
        $this->assertNotNull($nonce, 'В заголовке должен быть nonce');

        $html = $response->getContent();
        preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, $inlineScripts);

        $this->assertNotEmpty($inlineScripts[0], 'На странице поста есть инлайновые скрипты');

        foreach ($inlineScripts[0] as $tag) {
            $this->assertStringContainsString(
                'nonce="'.$nonce.'"',
                $tag,
                'Инлайновый скрипт без nonce был бы заблокирован политикой: '.$tag
            );
        }
    }

    public function test_admin_panel_keeps_relaxed_policy(): void
    {
        // Filament и Livewire вставляют свои инлайны без nonce, а Alpine
        // вычисляет выражения через new Function — там политика мягче.
        $response = $this->get('/'.config('admin.panel_path', 'filament').'/login');

        $scriptSrc = $this->scriptSrc($response->headers->get('Content-Security-Policy'));

        $this->assertStringContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringContainsString("'unsafe-eval'", $scriptSrc);
    }
}

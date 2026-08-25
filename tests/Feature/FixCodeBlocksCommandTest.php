<?php

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Восстановление кода, вынесенного HTMLPurifier из <pre><code>.
 *
 * Причину чинит HtmlSanitizerService, но пятнадцати уже сохранённым постам это
 * не помогает: исходной разметки нет ни в content, ни в content_orig. Код при
 * этом не потерян — он лежит соседями рядом с пустым блоком, и форма выноса
 * детерминированная, потому что её задаёт модель контента HTML.
 */
class FixCodeBlocksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_hoisted_lines_go_back_into_the_block(): void
    {
        $post = $this->postWithBrokenCode();

        $this->artisan('posts:fix-code-blocks')->assertSuccessful();

        $content = $post->refresh()->content;

        $this->assertStringNotContainsString('<pre><code></code></pre>', $content, 'блок остался пустым');
        $this->assertMatchesRegularExpression('#<pre><code>&lt;\?php\nnamespace App;#s', $content);
        $this->assertStringContainsString('echo 1;</code></pre>', $content);
        // Соседей-сирот после сборки остаться не должно.
        $this->assertStringNotContainsString('<div><code>', $content);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $post = $this->postWithBrokenCode();
        $before = $post->content;

        $this->artisan('posts:fix-code-blocks', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $post->refresh()->content);
    }

    public function test_healthy_post_is_left_alone(): void
    {
        $healthy = Post::withoutSyncingToSearch(fn (): Post => Post::create([
            'title' => 'Целый',
            'code' => 'celyj',
            'content' => '<p>Текст.</p><pre><code>echo 1;</code></pre>',
        ]));

        $before = $healthy->content;

        $this->artisan('posts:fix-code-blocks')->assertSuccessful();

        $this->assertSame($before, $healthy->refresh()->content);
    }

    public function test_entities_stay_escaped(): void
    {
        // Код в вынесенных строках уже экранирован. Раскодировав его при
        // сборке, мы вернули бы в разметку живой тег — то есть XSS.
        $post = Post::withoutSyncingToSearch(fn (): Post => Post::create([
            'title' => 'Опасный',
            'code' => 'opasnyj',
            'content' => '<pre><code></code></pre>'
                .'<div><code><span>&lt;script&gt;alert(1)&lt;/script&gt;</span></code></div><code></code>',
        ]));

        $this->artisan('posts:fix-code-blocks')->assertSuccessful();

        $content = $post->refresh()->content;

        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    private function postWithBrokenCode(): Post
    {
        return Post::withoutSyncingToSearch(fn (): Post => Post::create([
            'title' => 'Битый блок кода',
            'code' => 'bityj-blok',
            'content' => '<p>До кода.</p>'
                .'<pre><code></code></pre>'
                .'<div><code><span>&lt;?php</span></code></div><code></code>'
                .'<div><code><span>namespace</span><span> </span><span>App</span><span>;</span></code></div><code></code>'
                .'<div><code><span>echo 1;</span></code></div><code></code>'
                .'<p>После кода.</p>',
        ]));
    }
}

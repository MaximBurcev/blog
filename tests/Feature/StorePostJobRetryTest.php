<?php

namespace Tests\Feature;

use App\Events\PostCreated;
use App\Exceptions\TransientFetchException;
use App\Jobs\StorePostJob;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Регрессия на audit-2026-08-01: handle() ловил \Throwable целиком и в любом
 * случае доходил до PostService::store(), то есть завершался УСПЕШНО. Сетевой
 * сбой становился «неразобранным» постом с первой же попытки, а объявленные
 * $tries = 3 и $backoff = [60, 300, 900] не использовались никогда.
 *
 * Теперь временные сбои (таймаут, 5xx, 429, пустой ответ, антибот-заглушка)
 * летят наружу и обрабатываются очередью, а постоянные (404, недоступная по
 * проверке безопасности ссылка, нечитаемый файл) по-прежнему сохраняются как
 * пост-заглушка с parse_error.
 */
class StorePostJobRetryTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(array $data): void
    {
        Post::withoutSyncingToSearch(function () use ($data) {
            $this->app->call([new StorePostJob($data), 'handle']);
        });
    }

    private function withHtmlFile(string $contents, callable $callback): void
    {
        $file = tempnam(sys_get_temp_dir(), 'storepostjob_');
        file_put_contents($file, $contents);

        try {
            $callback($file);
        } finally {
            @unlink($file);
        }
    }

    public function test_antibot_challenge_is_transient_and_creates_no_stub(): void
    {
        $this->withHtmlFile('<html><head><title>Just a moment...</title></head><body></body></html>', function (string $file) {
            try {
                $this->runJob(['url' => '', 'html_file' => $file, 'selector' => '']);
                $this->fail('Ожидалось TransientFetchException — заглушка Cloudflare должна уходить в retry');
            } catch (TransientFetchException $exception) {
                $this->assertStringContainsString('антибот', $exception->getMessage());
            }
        });

        // Пост не создан: очередь повторит попытку, и статья может разобраться.
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_empty_response_is_transient(): void
    {
        $this->withHtmlFile('', function (string $file) {
            $this->expectException(TransientFetchException::class);
            $this->runJob(['url' => '', 'html_file' => $file, 'selector' => '']);
        });
    }

    public function test_unreadable_source_is_permanent_and_records_stub_post(): void
    {
        Event::fake([PostCreated::class]);

        $this->runJob([
            'url' => '',
            'html_file' => '/tmp/storepostjob-no-such-file-'.uniqid().'.html',
            'selector' => '',
        ]);

        // Повторять нечего — фиксируем результат на посте, чтобы ссылка была
        // видна в админке с причиной.
        $this->assertDatabaseCount('posts', 1);
        $post = Post::first();
        $this->assertSame(Post::PARSE_STATUS_FAILED, $post->parse_status);
        $this->assertStringContainsString('Не удалось прочитать файл', (string) $post->parse_error);
        $this->assertFalse((bool) $post->published);
    }

    public function test_failed_creates_stub_post_after_attempts_are_exhausted(): void
    {
        Event::fake([PostCreated::class]);

        $job = new StorePostJob([
            'url' => 'https://example.com/article-that-times-out',
            'link_text' => 'Заголовок из дайджеста',
            'selector' => '',
        ]);

        Post::withoutSyncingToSearch(function () use ($job) {
            $job->failed(new TransientFetchException('Источник не ответил: таймаут'));
        });

        $post = Post::first();
        $this->assertNotNull($post, 'После исчерпания попыток ссылка не должна теряться');
        $this->assertSame('https://example.com/article-that-times-out', $post->url);
        $this->assertSame(Post::PARSE_STATUS_FAILED, $post->parse_status);
        $this->assertStringContainsString('за 3 попыток', (string) $post->parse_error);
        $this->assertStringContainsString('таймаут', (string) $post->parse_error);
        // Заголовок берётся из текста ссылки на странице релиза.
        $this->assertSame('Заголовок из дайджеста', $post->title);
    }
}

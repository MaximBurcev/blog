<?php

namespace Tests\Feature;

use App\Jobs\SubmitUrlToIndexNow;
use App\Models\Post;
use App\Service\IndexNowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IndexNowTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'indexnow.enabled' => true,
            'indexnow.key' => self::KEY,
            'app.url' => 'https://maxburcev.ru',
        ]);
    }

    private function makePost(array $attributes = []): Post
    {
        static $n = 0;
        $n++;

        return Post::withoutSyncingToSearch(fn (): Post => Post::create(array_merge([
            'title' => 'Заголовок '.$n,
            'content' => '<p>Текст записи '.$n.'.</p>',
            'code' => 'zapis-'.$n,
            'published' => false,
            'is_news' => false,
        ], $attributes)));
    }

    public function test_key_file_is_served_at_root(): void
    {
        $this->get('/'.self::KEY.'.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText(self::KEY);

        // Чужой ключ не должен отвечать 200.
        $this->get('/ffffffffffffffffffffffffffffffff.txt')->assertNotFound();
    }

    public function test_key_route_absent_without_key(): void
    {
        config(['indexnow.key' => null]);

        // Без ключа адрес отдаёт 404 даже для строки, похожей на ключ.
        $this->get('/'.self::KEY.'.txt')->assertNotFound();

        Http::fake();

        $this->assertFalse(app(IndexNowService::class)->submit(['https://maxburcev.ru/posts/x']));

        Http::assertNothingSent();
    }

    public function test_submit_posts_url_list(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);

        $ok = app(IndexNowService::class)->submit(['https://maxburcev.ru/posts/a', 'https://maxburcev.ru/news/b']);

        $this->assertTrue($ok);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.indexnow.org/indexnow'
            && $request['host'] === 'maxburcev.ru'
            && $request['key'] === self::KEY
            && $request['keyLocation'] === 'https://maxburcev.ru/'.self::KEY.'.txt'
            && $request['urlList'] === ['https://maxburcev.ru/posts/a', 'https://maxburcev.ru/news/b']);
    }

    public function test_submit_tolerates_api_failure(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('Invalid key', 422)]);

        // Не 2xx — false и warning в лог, исключений наружу нет.
        $this->assertFalse(app(IndexNowService::class)->submit(['https://maxburcev.ru/posts/a']));
    }

    public function test_publishing_dispatches_indexnow_job(): void
    {
        Bus::fake();

        $post = $this->makePost();
        $post->update(['published' => true]);

        Bus::assertDispatched(SubmitUrlToIndexNow::class);

        // Правка опубликованного поста без смены флага пинговать не должна.
        Bus::fake();
        $post->update(['title' => 'Новый заголовок']);

        Bus::assertNotDispatched(SubmitUrlToIndexNow::class);
    }

    public function test_command_submits_all_published(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);

        $published = $this->makePost(['published' => true]);
        $this->makePost(); // черновик не уходит

        $this->artisan('indexnow:submit', ['--all' => true])->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request['urlList'] === [$published->permalink()]);
    }
}

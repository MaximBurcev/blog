<?php

namespace Tests\Feature;

use App\Service\ChallengeSolverClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FlareSolverr открывает страницу в headless-браузере и потому проходит
 * Cloudflare managed challenge, недостижимый для curl. Нужен для статей
 * medium.com, которых нет в RSS (фид отдаёт только последние десять
 * публикаций автора).
 *
 * По умолчанию выключен: без FLARESOLVERR_URL пайплайн работает как прежде.
 */
class ChallengeSolverClientTest extends TestCase
{
    private function enable(): void
    {
        Config::set('releases.challenge_solver_url', 'http://flaresolverr:8191/v1');
        Config::set('releases.challenge_solver_timeout', 60);
    }

    public function test_disabled_without_url_and_makes_no_requests(): void
    {
        Config::set('releases.challenge_solver_url', null);
        Http::fake();

        $client = new ChallengeSolverClient;

        $this->assertFalse($client->isEnabled());
        $this->assertNull($client->solve('https://medium.com/@a/x-1ca68f019664'));
        Http::assertNothingSent();
    }

    public function test_returns_html_when_challenge_is_solved(): void
    {
        $this->enable();
        Http::fake(['*' => Http::response([
            'status' => 'ok',
            'message' => 'Challenge solved!',
            'solution' => ['status' => 200, 'url' => 'https://medium.com/@a/x', 'response' => '<html><body><h1>Статья</h1></body></html>'],
        ], 200)]);

        $html = (new ChallengeSolverClient)->solve('https://medium.com/@a/x-1ca68f019664');

        $this->assertStringContainsString('<h1>Статья</h1>', (string) $html);
        Http::assertSent(fn ($r) => $r['cmd'] === 'request.get'
            && $r['url'] === 'https://medium.com/@a/x-1ca68f019664'
            && $r['maxTimeout'] === 60000);
    }

    public function test_returns_null_when_solver_reports_failure(): void
    {
        $this->enable();
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Challenge not solved!'], 200)]);

        $this->assertNull((new ChallengeSolverClient)->solve('https://medium.com/@a/x-1ca68f019664'));
    }

    public function test_returns_null_on_http_error(): void
    {
        $this->enable();
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertNull((new ChallengeSolverClient)->solve('https://medium.com/@a/x-1ca68f019664'));
    }

    /**
     * Сервис может быть просто не поднят — джоба из-за этого падать не должна,
     * у неё есть свои пути обработки сбоя.
     */
    public function test_returns_null_when_service_is_unreachable(): void
    {
        $this->enable();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertNull((new ChallengeSolverClient)->solve('https://medium.com/@a/x-1ca68f019664'));
    }

    public function test_returns_null_on_empty_response_body(): void
    {
        $this->enable();
        Http::fake(['*' => Http::response(['status' => 'ok', 'solution' => ['status' => 200, 'response' => '']], 200)]);

        $this->assertNull((new ChallengeSolverClient)->solve('https://medium.com/@a/x-1ca68f019664'));
    }
}

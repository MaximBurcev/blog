<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Живость приложения для внешнего мониторинга.
 *
 * До 18.08.2026 наружу не торчало ничего: падение сайта обнаруживалось
 * посетителями. Адрес публичный, поэтому проверяется и обратное — что он не
 * рассказывает лишнего о том, из чего приложение состоит.
 */
class HealthCheckTest extends TestCase
{
    public function test_healthy_application_answers_ok(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_broken_dependency_answers_503(): void
    {
        // Роняем БД, а не что-то абстрактное: это самая частая причина, по
        // которой сайт жив как процесс, но обслуживать запросы не может.
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('соединение потеряно'));
        Log::spy();

        $this->get('/up')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'fail']);
    }

    public function test_failure_reason_goes_to_log_not_to_the_response(): void
    {
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('пароль не подошёл'));
        Log::spy();

        $response = $this->get('/up');

        // Наружу — только статус: адрес публичный, и «что именно легло» —
        // подсказка тому, кто её не заслужил.
        $this->assertStringNotContainsString('пароль', $response->getContent());
        $this->assertStringNotContainsString('database', $response->getContent());

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'Health-check'))
            ->once();
    }

    public function test_cache_that_does_not_return_what_was_written_is_a_failure(): void
    {
        // «Пишется, но не читается» — реальное состояние файлового кэша при
        // сбитых правах, и одним get() его не поймать.
        Cache::shouldReceive('put')->andReturnTrue();
        Cache::shouldReceive('get')->andReturn(null);
        Log::spy();

        $this->get('/up')->assertStatus(503);
    }

    public function test_endpoint_is_not_throttled(): void
    {
        // Монитор ходит сюда раз в минуту; упереться в общий лимитер он не
        // должен.
        foreach (range(1, 40) as $ignored) {
            $this->get('/up')->assertOk();
        }
    }

    /**
     * Маршрут живёт вне группы web — без сессии, cookie и CSRF.
     *
     * В группе web он получал StartSession, и монитор, опрашивающий адрес раз
     * в минуту, оставлял бы по файлу сессии на запрос: полторы тысячи в сутки
     * в shared-каталоге, который переживает релизы. Ровно тот класс тихого
     * роста, из-за которого пришлось чинить ротацию логов.
     */
    public function test_monitor_does_not_leave_a_session_behind(): void
    {
        $response = $this->get('/up');

        $this->assertSame([], $response->headers->getCookies(), 'health-check ставит cookie — значит стартует сессию');

        // Глобальный стек при этом обязан работать: он объявлен в
        // Http\Kernel::$middleware и от групп не зависит.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}

<?php

namespace Tests\Feature;

use RuntimeException;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Tests\TestCase;

/**
 * Sentry не должен обходить приватность, выстроенную в остальном приложении.
 *
 * Аналитика блога построена на неидентифицируемости посетителя: в post_views
 * пишутся HMAC-псевдонимы вместо IP и session id, User-Agent не сохраняется
 * вовсе, от реферера остаётся только хост. Трекер ошибок, отправляющий тело
 * запроса или содержимое security-лога, обошёл бы это решение через заднюю
 * дверь.
 *
 * Проверяются НЕ значения по умолчанию (они у пакета и так безопасные), а
 * ровно те настройки, которые пришлось задать вручную.
 */
class SentryConfigurationTest extends TestCase
{
    public function test_request_body_is_never_sent(): void
    {
        // Главная дыра: тело прикрепляется ВНЕ проверки send_default_pii
        // (Sentry\Integration\RequestIntegration), а дефолт SDK — 10 КБ. Любая
        // ошибка на отправке комментария или логине увезла бы текст, имя гостя
        // и email.
        $this->assertSame('none', config('sentry.max_request_body_size'));
    }

    public function test_security_log_does_not_become_breadcrumbs(): void
    {
        // Крошка из лога несёт context записи целиком, а канал security пишет
        // actor_email, ip и user_agent — уровнем info, то есть фильтром уровня
        // не отсекается.
        $this->assertFalse(config('sentry.breadcrumbs.logs'));
    }

    public function test_visitor_data_and_query_bindings_stay_out(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
    }

    public function test_tracing_is_off(): void
    {
        // Трассировка шлёт заметный объём данных и стоит денег, а задача —
        // узнавать о падениях.
        $this->assertSame(0.0, config('sentry.traces_sample_rate'));
    }

    public function test_exceptions_are_reported_to_sentry(): void
    {
        // Настоящий хаб без DSN молча ничего не делает, поэтому тест не отличил
        // бы работающую интеграцию от вырезанной — подменяем.
        $original = SentrySdk::getCurrentHub();

        $hub = \Mockery::mock(HubInterface::class);
        $hub->shouldReceive('captureException')->once();
        // Эти зовёт сам пакет при бутстрапе и разрешении контекста; без них
        // мок роняет создание приложения в следующем тесте.
        $hub->shouldReceive('getClient')->andReturnNull();
        $hub->shouldReceive('configureScope')->andReturnNull();
        $hub->shouldReceive('pushScope')->andReturnNull();
        $hub->shouldReceive('popScope')->andReturnTrue();

        SentrySdk::setCurrentHub($hub);

        try {
            // report(), а не HTTP-запрос: 404 и прочие HttpException в отчёт не
            // попадают вовсе (framework $internalDontReport), и такой тест был
            // бы зелёным при полностью отключённой интеграции.
            report(new RuntimeException('проверка отправки в Sentry'));

            $hub->shouldHaveReceived('captureException');
        } finally {
            // Хаб глобальный: не вернув оригинал, мы утащили бы мок в бутстрап
            // соседних тестов.
            SentrySdk::setCurrentHub($original);
        }
    }

    public function test_release_is_taken_from_the_deployed_directory(): void
    {
        // Envoy разворачивает releases/<метка времени>; имя каталога и есть
        // версия, по которой ошибки группируются по выкату.
        $this->assertSame(basename(base_path()), config('sentry.release'));
    }
}

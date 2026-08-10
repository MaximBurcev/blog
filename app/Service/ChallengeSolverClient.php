<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клиент FlareSolverr — внешнего сервиса, который открывает страницу в
 * headless-браузере и потому проходит antibot-проверки, требующие
 * исполнения JS.
 *
 * Нужен для площадок вроде medium.com: Cloudflare managed challenge
 * недостижим для curl в принципе (curl-impersonate подменяет TLS-отпечаток,
 * но скрипт выполнить нечем), а RSS-обходной путь работает только для
 * последних десяти публикаций автора.
 *
 * Выключен по умолчанию: без FLARESOLVERR_URL класс просто не вызывается.
 * Замеры на боевом образе: 123 МБ в покое, ~145 МБ под запросом, ~20 с
 * на решение challenge.
 *
 * ⚠ Ограничение по безопасности: редиректы внутри FlareSolverr идут мимо
 * нашего UrlSafetyChecker и IP-пиннинга — браузер ходит сам. Поэтому сюда
 * передаются только адреса, уже прошедшие проверку в StorePostJob, а сам
 * контейнер стоит запускать в изолированной сети, чтобы из него не
 * дотянуться до внутренних сервисов.
 */
class ChallengeSolverClient
{
    public function isEnabled(): bool
    {
        return filled(config('releases.challenge_solver_url'));
    }

    /**
     * Возвращает HTML страницы после прохождения проверки либо null, если
     * сервис выключен, недоступен или не справился.
     */
    public function solve(string $url): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $endpoint = (string) config('releases.challenge_solver_url');
        $timeout = (int) config('releases.challenge_solver_timeout', 60);

        try {
            // Своим таймаутам даём запас над maxTimeout самого FlareSolverr:
            // иначе HTTP-клиент отвалится раньше, чем сервис успеет ответить
            // осмысленной ошибкой.
            $response = Http::timeout($timeout + 15)
                ->acceptJson()
                ->post($endpoint, [
                    'cmd' => 'request.get',
                    'url' => $url,
                    'maxTimeout' => $timeout * 1000,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('ChallengeSolver: сервис недоступен', [
                'endpoint' => $endpoint,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('ChallengeSolver: HTTP-ошибка сервиса', ['status' => $response->status()]);

            return null;
        }

        $body = $response->json();

        if (($body['status'] ?? null) !== 'ok') {
            Log::warning('ChallengeSolver: проверка не пройдена', [
                'url' => $url,
                'message' => mb_substr((string) ($body['message'] ?? ''), 0, 200),
            ]);

            return null;
        }

        $html = $body['solution']['response'] ?? null;
        $status = $body['solution']['status'] ?? null;

        if (! is_string($html) || $html === '') {
            return null;
        }

        Log::info('ChallengeSolver: проверка пройдена', [
            'url' => $url,
            'upstream_status' => $status,
            'length' => strlen($html),
        ]);

        return $html;
    }
}

<?php

namespace App\Service;

use App\Support\HostMatcher;
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
    /**
     * Совпадает ли хост итогового адреса с запрошенным. Сравнение по границе
     * метки: поддомен того же сайта допустим (medium.com -> www.medium.com),
     * чужой хост — нет.
     */
    private function isSameHost(string $requested, string $final): bool
    {
        $a = strtolower((string) parse_url($requested, PHP_URL_HOST));
        $b = strtolower((string) parse_url($final, PHP_URL_HOST));

        if ($a === '' || $b === '') {
            return false;
        }

        return HostMatcher::matches($b, $a) || HostMatcher::matches($a, $b);
    }

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
        $finalUrl = $body['solution']['url'] ?? null;

        if (! is_string($html) || $html === '') {
            return null;
        }

        // Браузер идёт по редиректам сам, мимо UrlSafetyChecker и IP-пиннинга.
        // Поэтому сверяем, где он в итоге оказался: чужая страница могла
        // отдать 302 на 169.254.169.254 или во внутреннюю сеть, и её содержимое
        // приехало бы к нам как «статья».
        if (! is_string($finalUrl) || ! $this->isSameHost($url, $finalUrl)) {
            Log::warning('ChallengeSolver: браузер ушёл на другой хост', [
                'requested' => $url,
                'final' => is_string($finalUrl) ? $finalUrl : null,
            ]);

            return null;
        }

        // Лимит размера: в curl-ветках стоит CURLOPT_MAXFILESIZE_LARGE, а здесь
        // тело приезжает уже целиком в памяти. Страница на сотни мегабайт
        // положила бы воркер очереди.
        $maxLength = (int) config('releases.max_content_length');

        if ($maxLength > 0 && strlen($html) > $maxLength) {
            Log::warning('ChallengeSolver: ответ превышает лимит размера', [
                'url' => $url,
                'length' => strlen($html),
                'limit' => $maxLength,
            ]);

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

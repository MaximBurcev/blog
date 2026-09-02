<?php

namespace App\Service;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Отправка адресов в IndexNow (Яндекс, Bing).
 *
 * Сервис никогда не бросает исключения наружу: уведомление поисковика —
 * побочная функция публикации, её сбой не должен валить ни сохранение поста,
 * ни очередь. Все неудачи — warning в лог и false на выходе.
 */
class IndexNowService
{
    /**
     * @param  string[]  $urls
     */
    public function submit(array $urls): bool
    {
        $urls = array_values(array_filter($urls));

        if ($urls === []) {
            return false;
        }

        $key = (string) config('indexnow.key');

        if (! config('indexnow.enabled') || $key === '') {
            return false;
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        try {
            $response = Http::timeout(10)->post((string) config('indexnow.endpoint'), [
                'host' => $host,
                'key' => $key,
                // Поисковик сверяет ключ с содержимым этого файла — без него
                // отправка отвергается. Отдаёт маршрут в web.php.
                'keyLocation' => sprintf('%s://%s/%s.txt', 'https', $host, $key),
                'urlList' => $urls,
            ]);

            // Протокол отвечает 200 (принято) или 202 (принято, ключ ждёт
            // проверки). Остальное — отказ: ключ не совпал, формат невалиден.
            if (! in_array($response->status(), [200, 202], true)) {
                Log::warning('IndexNow: отправка отклонена', [
                    'status' => $response->status(),
                    'urls' => $urls,
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('IndexNow: запрос не удался', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}

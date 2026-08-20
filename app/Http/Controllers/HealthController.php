<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Проверка живости для внешнего мониторинга.
 *
 * Приложение на легаси-скелете, поэтому встроенного `/up` из Laravel 11+ тут
 * нет — маршрут свой. До 18.08.2026 наружу не торчало ничего: падение сайта
 * обнаруживалось посетителями, а единственным автоматическим сигналом было
 * письмо backup:monitor в 06:00.
 *
 * Наружу отдаётся только статус, без состава проверок: адрес публичный, и
 * подробности «что именно легло» — подсказка тому, кто её не заслужил.
 * Разбираться нужно по логу, куда причина и пишется.
 *
 * Проверки намеренно дешёвые: монитор дёргает адрес раз в минуту, и health,
 * который сам нагружает БД, — плохой health.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $failed = [];

        foreach ($this->checks() as $name => $check) {
            try {
                $check();
            } catch (Throwable $e) {
                // Класс исключения, а не текст: сообщение PDOException несёт
                // хост, порт, имя базы и пользователя («Access denied for user
                // 'blog'@'10.0.0.5'»), а лог виден через /log-viewer и, если
                // подключён Sentry, уезжает наружу.
                $failed[$name] = $e::class;
            }
        }

        if ($failed !== []) {
            Log::error('Health-check не прошёл', ['failed' => $failed]);

            // 503, а не 500: сервис жив настолько, что смог ответить, но
            // обслуживать запросы не готов — именно это должен видеть монитор
            // и балансировщик.
            return $this->answer('fail', 503);
        }

        return $this->answer('ok', 200);
    }

    /**
     * no-store обязателен: между монитором и приложением может оказаться
     * прокси или CDN, и закэшированный «ok» превращает проверку живости в
     * проверку живости кэша.
     */
    private function answer(string $status, int $code): JsonResponse
    {
        return response()
            ->json(['status' => $status], $code)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * @return array<string, callable>
     */
    private function checks(): array
    {
        return [
            // Соединение, а не запрос к таблице: цель — убедиться, что БД
            // отвечает, а не что в ней есть данные.
            'database' => static fn () => DB::connection()->getPdo(),

            // Кэш проверяется записью и чтением: файловый драйвер умеет
            // отваливаться по правам, и «пишется, но не читается» — реальное
            // состояние, которое одним get() не поймать.
            'cache' => static function () {
                $key = 'health-check';
                Cache::put($key, 'ok', 10);

                if (Cache::get($key) !== 'ok') {
                    throw new \RuntimeException('кэш не возвращает записанное значение');
                }
            },

            // Каталог загрузок: сюда пишет парсер, и его права на проде уже
            // отбирались деплоем — картинки постов тогда перестали появляться.
            'storage' => static function () {
                if (! is_writable(storage_path('app/public'))) {
                    throw new \RuntimeException('storage/app/public недоступен для записи');
                }
            },
        ];
    }
}

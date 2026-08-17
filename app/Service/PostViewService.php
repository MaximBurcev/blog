<?php

namespace App\Service;

use App\Models\Post;
use App\Models\PostView;
use App\Support\BotDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PostViewService
{
    // В пределах этого окна повторный заход того же посетителя (по сессии
    // или IP) на один пост не засчитывается новым просмотром — базовая
    // защита от накрутки через F5 / переоткрытие вкладки.
    private const DEDUP_WINDOW_HOURS = 24;

    public function record(Post $post, Request $request): void
    {
        $sessionHash = $request->hasSession() ? $this->pseudonymize($request->session()->getId()) : null;
        $ipHash = $request->ip() !== null ? $this->pseudonymize($request->ip()) : null;
        $isBot = BotDetector::isBot($request->userAgent());

        // Гонка: alreadyViewed() (SELECT) и create() (INSERT) не атомарны и
        // не прикрыты уникальным индексом. Параллельные запросы одного
        // посетителя — несколько вкладок или скрипт в N потоков с одной
        // сессией — проходили проверку одновременно и создавали N записей,
        // то есть окно дедупа обходилось ровно на величину параллелизма.
        // Cache::add атомарен и делает заявку на просмотр однократной.
        if (! $this->claimView($post, $sessionHash ?? $ipHash)) {
            return;
        }

        if ($this->alreadyViewed($post, $sessionHash, $ipHash)) {
            return;
        }

        $post->views()->create([
            'ip_hash' => $ipHash,
            'session_hash' => $sessionHash,
            'is_bot' => $isBot,
            'viewed_at' => now(),
            ...$this->attribution($request),
        ]);
    }

    /**
     * Откуда пришёл читатель: домен-источник и метки кампании.
     *
     * От реферера берём только хост. Путь и query чужой страницы — это чужие
     * данные (поисковый запрос, идентификатор письма, приватный чат), хранить
     * их ради ответа «откуда трафик» незачем: на вопрос отвечает домен.
     *
     * Переход внутри сайта пишется собственным хостом, а не NULL. Свалив его в
     * NULL, мы бы смешали «читатель кликнул по ссылке с главной» с «реферера не
     * было вовсе», и виджет назвал бы внутреннюю навигацию прямыми заходами —
     * на блоге с перелинковкой это большинство просмотров. Отличить их потом
     * было бы нечем: в NULL информация теряется безвозвратно.
     */
    private function attribution(Request $request): array
    {
        return [
            'referer_host' => $this->refererHost($request),
            'utm_source' => $this->utm($request, 'utm_source'),
            'utm_medium' => $this->utm($request, 'utm_medium'),
            'utm_campaign' => $this->utm($request, 'utm_campaign'),
        ];
    }

    private function refererHost(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer');

        if ($referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        return self::normalizeHost($host);
    }

    /**
     * Хост из заголовка запроса — произвольная строка: parse_url отдаёт хостом
     * и '<script>alert(1)<', и 'ev"il.com'. В админке это экранируется, но
     * храниться как «домен» такое не должно: строка переживёт вывод и всплывёт
     * в экспорте или в логах. Отсюда проверка алфавита, а не только длины.
     *
     * Завершающая точка снимается, как и во всех остальных разборах хоста в
     * проекте (HostMatcher, UrlSafetyChecker, FeedArticleLocator): иначе
     * 'habr.com.' встал бы в отчёте отдельной строкой рядом с 'habr.com', и
     * разбить топ источников мог бы кто угодно одним заголовком.
     */
    public static function normalizeHost(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        if ($host === '' || strlen($host) > 255) {
            return null;
        }

        return preg_match('/^[a-z0-9.\-]+$/', $host) === 1 ? $host : null;
    }

    /**
     * Метки кампании приходят из адресной строки, то есть пишет их кто угодно.
     * Отсюда обрезка по длине, отказ от нескалярных значений (?utm_source[]=a
     * приходит массивом и свалил бы запись просмотра) и ограничение алфавита:
     * метка — это идентификатор кампании, а не место для произвольного текста,
     * которым можно замусорить группировку в отчёте.
     */
    private function utm(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value)) {
            return null;
        }

        $value = mb_substr(trim($value), 0, 100);

        if ($value === '') {
            return null;
        }

        return preg_match('/^[\w.\-]+$/u', $value) === 1 ? $value : null;
    }

    /**
     * Занимает слот просмотра на окно дедупа. false — слот уже занят, то есть
     * этот посетитель в окне уже учтён.
     *
     * Кэш здесь не источник истины, а только защёлка от гонки: сброс кэша
     * просто вернёт нас к проверке по БД, которая осталась на месте.
     */
    private function claimView(Post $post, ?string $identifier): bool
    {
        if ($identifier === null) {
            return true;
        }

        return Cache::add(
            'post-view:'.$post->getKey().':'.$identifier,
            true,
            now()->addHours(self::DEDUP_WINDOW_HOURS)
        );
    }

    /**
     * HMAC, а не голый hash(): id сессии — это токен доступа, а несолёный
     * sha256 от IPv4 разворачивается перебором 2^32 значений за минуты. Ключ
     * приложения не лежит в БД, поэтому утечка дампа больше не даёт ни сессий,
     * ни исходных IP. Побочный эффект: смена APP_KEY обнуляет дедуп просмотров.
     */
    private function pseudonymize(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function alreadyViewed(Post $post, ?string $sessionHash, ?string $ipHash): bool
    {
        // Нечем идентифицировать посетителя — считаем просмотр новым, но
        // без session/ip записи всё равно попадут в общий счётчик.
        if ($sessionHash === null && $ipHash === null) {
            return false;
        }

        // Скоуп «только люди» здесь снимается намеренно: окно дедупа обязано
        // накрывать и роботов, иначе краулер, чья прошлая запись помечена
        // is_bot, каждый раз выглядел бы новым посетителем и плодил строки.
        return $post->views()
            ->withoutGlobalScope(PostView::HUMANS_ONLY)
            ->where('viewed_at', '>=', now()->subHours(self::DEDUP_WINDOW_HOURS))
            ->where(function ($query) use ($sessionHash, $ipHash) {
                if ($sessionHash !== null) {
                    $query->orWhere('session_hash', $sessionHash);
                }
                if ($ipHash !== null) {
                    $query->orWhere('ip_hash', $ipHash);
                }
            })
            ->exists();
    }
}

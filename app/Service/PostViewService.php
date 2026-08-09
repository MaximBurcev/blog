<?php

namespace App\Service;

use App\Models\Post;
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
            'viewed_at' => now(),
        ]);
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

        return $post->views()
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

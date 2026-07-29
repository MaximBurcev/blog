<?php

namespace App\Service;

use App\Models\Post;
use Illuminate\Http\Request;

class PostViewService
{
    // В пределах этого окна повторный заход того же посетителя (по сессии
    // или IP) на один пост не засчитывается новым просмотром — базовая
    // защита от накрутки через F5 / переоткрытие вкладки.
    private const DEDUP_WINDOW_HOURS = 24;

    public function record(Post $post, Request $request): void
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $ipHash = $request->ip() !== null ? hash('sha256', $request->ip()) : null;

        if ($this->alreadyViewed($post, $sessionId, $ipHash)) {
            return;
        }

        $post->views()->create([
            'ip_hash' => $ipHash,
            'session_id' => $sessionId,
            'viewed_at' => now(),
        ]);
    }

    private function alreadyViewed(Post $post, ?string $sessionId, ?string $ipHash): bool
    {
        // Нечем идентифицировать посетителя — считаем просмотр новым, но
        // без session/ip записи всё равно попадут в общий счётчик.
        if ($sessionId === null && $ipHash === null) {
            return false;
        }

        return $post->views()
            ->where('viewed_at', '>=', now()->subHours(self::DEDUP_WINDOW_HOURS))
            ->where(function ($query) use ($sessionId, $ipHash) {
                if ($sessionId !== null) {
                    $query->orWhere('session_id', $sessionId);
                }
                if ($ipHash !== null) {
                    $query->orWhere('ip_hash', $ipHash);
                }
            })
            ->exists();
    }
}

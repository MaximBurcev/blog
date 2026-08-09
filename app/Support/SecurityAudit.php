<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Единая точка записи audit-trail событий безопасности (security-audit
 * 2026-08-01, INF-7): до этого логины, неудачные попытки подбора, смена роли
 * и пароля не фиксировались нигде, и разобрать инцидент постфактум было не по
 * чему.
 *
 * Пишет в отдельный канал `security` (config/logging.php) — см. там про срок
 * хранения и захардкоженный level.
 */
final class SecurityAudit
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(string $event, array $context = []): void
    {
        Log::channel('security')->info($event, array_merge(self::actorContext(), $context));
    }

    /**
     * Кто и откуда. В консоли (artisan, queue:work) HTTP-запроса нет, и
     * request()->ip() вернул бы адрес из окружения воркера — это ввело бы
     * в заблуждение при разборе инцидента, поэтому явное 'cli'.
     *
     * @return array<string, mixed>
     */
    private static function actorContext(): array
    {
        $actor = auth()->user();

        $context = [
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_email' => $actor?->email,
        ];

        if (app()->runningInConsole()) {
            return $context + ['ip' => 'cli', 'user_agent' => 'cli'];
        }

        return $context + [
            'ip' => request()->ip(),
            // Обрезаем: User-Agent задаёт клиент, и ничто не мешает прислать
            // килобайты мусора в каждую неудачную попытку входа.
            'user_agent' => Str::limit((string) request()->userAgent(), 255, ''),
        ];
    }
}

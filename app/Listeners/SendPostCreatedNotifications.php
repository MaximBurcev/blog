<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\PostCreated;
use App\Events\UserNotification;
use App\Models\User;
use App\Notifications\PostCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Оба вызова внутри — это постановка задач в очередь, а не сама отправка:
 * UserNotification реализует ShouldBroadcast, PostCreatedNotification —
 * ShouldQueue. Поэтому исключения здесь означают «не удалось поставить в
 * очередь», и глушить их нельзя: раньше try/catch вокруг каждого вызова
 * превращал сбой в строчку лога, из-за чего очередь считала листенер успешным,
 * ретраи не запускались и уведомление терялось навсегда.
 */
class SendPostCreatedNotifications implements ShouldQueue
{
    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function handle(PostCreated $event): void
    {
        $post = $event->post;
        $message = 'Создан новый пост: '.$post->title;

        User::where('role', UserRole::Admin)->each(function (User $user) use ($post, $message) {
            UserNotification::dispatch($user, $message);
            $user->notify(new PostCreatedNotification($post));
        });
    }

    public function failed(PostCreated $event, \Throwable $exception): void
    {
        Log::error('Не удалось разослать уведомления о новом посте', [
            'post_id' => $event->post->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

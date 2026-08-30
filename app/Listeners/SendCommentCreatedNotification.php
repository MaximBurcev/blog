<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\CommentCreated;
use App\Models\User;
use App\Notifications\CommentCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendCommentCreatedNotification implements ShouldQueue
{
    public function handle(CommentCreated $event): void
    {
        $comment = $event->comment;

        // Модерация комментариев — зона редактора, а не только админа
        // (роль Editor появилась 29.08.2026): письмо должно доехать до обоих,
        // иначе комментарий провисит на модерации, пока админ не заглянет.
        User::whereIn('role', [UserRole::Admin, UserRole::Editor])->each(function (User $user) use ($comment) {
            try {
                $user->notify(new CommentCreatedNotification($comment));
            } catch (\Exception $e) {
                Log::warning('CommentCreatedNotification: mail failed', ['error' => $e->getMessage()]);
            }
        });
    }
}

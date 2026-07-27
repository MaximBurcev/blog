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

        User::where('role', UserRole::Admin)->each(function (User $user) use ($comment) {
            try {
                $user->notify(new CommentCreatedNotification($comment));
            } catch (\Exception $e) {
                Log::warning('CommentCreatedNotification: mail failed', ['error' => $e->getMessage()]);
            }
        });
    }
}

<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\PostCreated;
use App\Events\UserNotification;
use App\Models\User;
use App\Notifications\PostCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SendPostCreatedNotifications implements ShouldQueue
{
    public function handle(PostCreated $event): void
    {
        $post = $event->post;
        $message = 'Создан новый пост: '.$post->title;

        User::where('role', UserRole::Admin)->each(function (User $user) use ($post, $message) {
            try {
                UserNotification::dispatch($user, $message);
            } catch (\Exception $e) {
                Log::warning('UserNotification: broadcast failed', ['error' => $e->getMessage()]);
            }
            try {
                $user->notify(new PostCreatedNotification($post));
            } catch (\Exception $e) {
                Log::warning('PostCreatedNotification: mail failed', ['error' => $e->getMessage()]);
            }
        });
    }
}

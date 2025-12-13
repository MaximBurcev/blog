<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class UserNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $targetUser,
        public string $message
    ) {
        // Можно добавить дополнительную логику при создании события
    }

    /**
     * Каналы, на которые событие будет транслироваться.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.' . $this->targetUser->id);
    }

    /**
     * Данные, которые будут отправлены клиенту.
     */
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'time'    => now()->toISOString(),
        ];
    }

    /**
     * Название события (по умолчанию — класс события).
     * Можно переопределить, если нужно другое имя на фронтенде.
     */
     public function broadcastAs(): string
     {
         return 'UserNotification';
     }
}

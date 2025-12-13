<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// app/Events/PostLiked.php
class PostLiked implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(public int $postId, public int $newLikesCount) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('post.' . $this->postId);
    }

    public function broadcastAs(): string
    {
        return 'post.liked';
    }
}

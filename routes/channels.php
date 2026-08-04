<?php

use App\Models\Post;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Канал отдаёт счётчик лайков (PostLiked). Заглушка `=> true` пускала любого
// аутентифицированного на канал любого поста, включая несуществующий и
// неопубликованный, — подписка подтверждала факт существования черновика.
Broadcast::channel('post.{id}', fn ($user, $id) => Post::whereKey($id)->published()->exists());

<?php

namespace App\Http\Controllers\Post\Like;

use App\Events\PostLiked;
use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Database\UniqueConstraintViolationException;

class StoreController extends Controller
{
    public function like(Post $post)
    {
        // См. Comment\StoreController: биндинг по id идёт мимо scopePublished,
        // и без проверки лайк ставился черновику, портя счётчик до публикации.
        abort_unless($post->published, 404);

        $userId = auth()->id();
        $like = $post->likes()->where('user_id', $userId)->first();

        if ($like) {
            $like->delete();
            $liked = false;
        } else {
            try {
                $post->likes()->create(['user_id' => $userId]);
            } catch (UniqueConstraintViolationException) {
                // Между проверкой выше и вставкой лайк успел поставить
                // параллельный запрос (двойной клик, дребезг кнопки): по
                // UNIQUE(post_id, user_id) вторая вставка падала, и вместо
                // идемпотентного ответа пользователь получал 500.
            }

            $liked = true;
        }

        $likesCount = $post->likes()->count();

        broadcast(new PostLiked($post->id, $likesCount))->toOthers();

        return response()->json(['likes' => $likesCount, 'liked' => $liked]);
    }
}

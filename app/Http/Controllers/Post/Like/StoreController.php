<?php

namespace App\Http\Controllers\Post\Like;

use App\Events\PostLiked;
use App\Http\Controllers\Controller;
use App\Models\Post;

class StoreController extends Controller
{
    public function like(Post $post)
    {
        // См. Comment\StoreController: биндинг по id идёт мимо scopePublished,
        // и без проверки лайк ставился черновику, портя счётчик до публикации.
        abort_unless($post->published, 404);

        $like = $post->likes()->where('user_id', auth()->id())->first();

        if ($like) {
            $like->delete();
            $liked = false;
        } else {
            $post->likes()->create(['user_id' => auth()->id()]);
            $liked = true;
        }

        $likesCount = $post->likes()->count();

        broadcast(new PostLiked($post->id, $likesCount))->toOthers();

        return response()->json(['likes' => $likesCount, 'liked' => $liked]);
    }
}

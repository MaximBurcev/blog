<?php

namespace App\Http\Controllers\Post\Like;

use App\Events\PostLiked;
use App\Http\Controllers\Controller;
use App\Models\Post;

class StoreController extends Controller
{
    public function like(Post $post)
    {
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

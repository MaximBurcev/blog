<?php

namespace App\Http\Controllers\Post\Like;
use App\Events\PostLiked;
use App\Http\Controllers\Controller;
use App\Models\Post;

class StoreController extends Controller
{
    public function like(Post $post)
    {
        $post->likes()->firstOrCreate(['user_id' => auth()->id()]);

        $likesCount = $post->likes()->count();

        broadcast(new PostLiked($post->id, $likesCount))->toOthers();

        return response()->json(['likes' => $likesCount]);
    }
}

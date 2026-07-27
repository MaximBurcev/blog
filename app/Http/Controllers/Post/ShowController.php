<?php

namespace App\Http\Controllers\Post;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Carbon\Carbon;

class ShowController extends Controller
{
    public function __invoke(string $code)
    {
        $post = Post::where('code', $code)->published()->firstOrFail();
        $date = Carbon::parse($post->created_at);
        $title = $post->title;
        $description = $post->excerpt();
        $ogImage = $post->main_image ? asset('storage/'.$post->main_image) : null;
        $ogType = 'article';

        $relatedPosts = Post::relatedTo($post)->get();

        $isLiked = auth()->check() && $post->likes()->where('user_id', auth()->id())->exists();

        $comments = $post->comments()->published()->with('user')->latest()->get();

        return view('post.show',
            compact('post', 'date', 'relatedPosts', 'title', 'description', 'ogImage', 'ogType', 'isLiked', 'comments'));
    }
}

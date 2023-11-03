<?php

namespace App\Http\Controllers\Post;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Carbon\Carbon;

class ShowController extends Controller
{
    public function __invoke(String $code)
    {
        try {
            $post = Post::where('code', $code)->where('published', true)->first();
            $date = Carbon::parse($post->created_at);
            $title = $post->title;
            $relatedPosts = Post::where('category_id', $post->category_id)
                ->where('id', '!=', $post->id)
                ->where('published', true)
                ->get()
                ->take(3);
            return view('post.show', compact('post', 'date', 'relatedPosts', 'title'));
        } catch (\Exception $exception) {
            abort(404);
        }

    }
}

<?php

namespace App\Http\Controllers\Tag;

use App\Http\Controllers\Controller;
use App\Models\Tag;

class ShowController extends Controller
{
    public function __invoke(string $code)
    {
        $tag = Tag::where('code', $code)->firstOrFail();
        $posts = $tag->posts()->published()->paginate(6);
        $title = 'Посты с тегом '.$tag->title;
        $description = 'Посты с тегом «'.$tag->title.'»';
        $canonical = $posts->currentPage() > 1
            ? $posts->url($posts->currentPage())
            : url()->current();

        return view('tags.show', compact('posts', 'tag', 'title', 'description', 'canonical'));
    }
}

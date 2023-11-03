<?php

namespace App\Http\Controllers\Tag;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Tag;

class ShowController extends Controller
{
    public function __invoke($code)
    {
        $tag = Tag::where('code', $code)->firstOrFail();
        $posts = $tag->posts()->where('published', true)->paginate(6);
        return view('tags.show', compact('posts', 'tag'));
    }
}

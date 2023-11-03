<?php

namespace App\Http\Controllers\Post\Comment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\Comment\StoreRequest;
use App\Models\Post;

class StoreController extends Controller
{
    public function __invoke(Post $post, StoreRequest $request)
    {
        $data = $request->validated();
        dd($data);
        $post->store($data);

        return redirect()->route('admin.post.index');
    }
}

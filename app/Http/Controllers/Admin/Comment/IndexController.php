<?php

namespace App\Http\Controllers\Admin\Comment;

use App\Http\Controllers\Admin\Post\BaseController;
use App\Models\Comment;

class IndexController extends BaseController
{
    public function __invoke()
    {
        $comments = Comment::all();

        return view('admin.comments.index', compact('comments'));
    }
}

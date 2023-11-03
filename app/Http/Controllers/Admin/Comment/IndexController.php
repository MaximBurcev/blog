<?php

namespace App\Http\Controllers\Admin\Comment;

use App\Http\Controllers\Admin\Post\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;

class IndexController extends BaseController
{
    public function __invoke()
    {
        $comments = Comment::all();
        return view('admin.comments.index', compact('comments'));
    }
}

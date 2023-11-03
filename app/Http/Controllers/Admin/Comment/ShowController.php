<?php

namespace App\Http\Controllers\Admin\Comment;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;

class ShowController extends BaseController
{
    public function __invoke(Comment $comment)
    {
        return view('admin.comments.show', compact('comment'));
    }
}

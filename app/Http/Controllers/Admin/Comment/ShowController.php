<?php

namespace App\Http\Controllers\Admin\Comment;

use App\Models\Comment;

class ShowController extends BaseController
{
    public function __invoke(Comment $comment)
    {
        return view('admin.comments.show', compact('comment'));
    }
}

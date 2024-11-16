<?php

namespace App\Http\Controllers\Admin\Release;


use App\Models\Comment;


class DeleteController extends BaseController
{
    public function __invoke(Comment $comment)
    {
        $comment->delete();
        return redirect()->route('admin.comment.index');
    }
}

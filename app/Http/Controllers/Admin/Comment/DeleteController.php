<?php

namespace App\Http\Controllers\Admin\Comment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\UpdateRequest;
use App\Models\Comment;
use App\Models\Post;

class DeleteController extends BaseController
{
    public function __invoke(Comment $comment)
    {
        $comment->delete();
        return redirect()->route('admin.comment.index');
    }
}

<?php

namespace App\Http\Controllers\Admin\Post;

class AddController extends BaseController
{
    public function __invoke()
    {
        return view('admin.posts.add');
    }
}

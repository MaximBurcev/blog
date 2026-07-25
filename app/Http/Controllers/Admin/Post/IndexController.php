<?php

namespace App\Http\Controllers\Admin\Post;

class IndexController extends BaseController
{
    public function __invoke()
    {
        return view('admin.posts.index');
    }
}

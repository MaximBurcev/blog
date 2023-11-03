<?php

namespace App\Http\Controllers\Admin\Post;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Tag;

class AddController extends BaseController
{
    public function __invoke()
    {
        return view('admin.posts.add');
    }
}

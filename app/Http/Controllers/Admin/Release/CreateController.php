<?php

namespace App\Http\Controllers\Admin\Release;

use App\Http\Controllers\Admin\Post\BaseController;
use App\Models\Category;
use App\Models\Tag;

class CreateController extends BaseController
{
    public function __invoke()
    {
        $categories = Category::all();
        $tags = Tag::all();
        return view('admin.releases.create', compact('categories', 'tags'));
    }
}

<?php

namespace App\Http\Controllers\Admin\Release;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Release;

class ShowController extends BaseController
{
    public function __invoke(Release $release)
    {
        return view('admin.releases.show', compact('release'));
    }
}

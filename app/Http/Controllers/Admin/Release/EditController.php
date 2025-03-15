<?php

namespace App\Http\Controllers\Admin\Release;

use App\Models\Category;
use App\Models\Post;
use App\Models\Release;
use App\Models\Tag;

class EditController extends BaseController
{
    public function __invoke(Release $release)
    {

        return view('admin.releases.edit', compact('release'));
    }
}

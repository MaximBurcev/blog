<?php

namespace App\Http\Controllers\Admin\Release;

use App\Models\Release;

class ShowController extends BaseController
{
    public function __invoke(Release $release)
    {
        return view('admin.releases.show', compact('release'));
    }
}

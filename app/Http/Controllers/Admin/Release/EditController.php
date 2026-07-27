<?php

namespace App\Http\Controllers\Admin\Release;

use App\Models\Release;

class EditController extends BaseController
{
    public function __invoke(Release $release)
    {

        return view('admin.releases.edit', compact('release'));
    }
}

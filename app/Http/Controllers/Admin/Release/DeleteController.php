<?php

namespace App\Http\Controllers\Admin\Release;


use App\Models\Release;


class DeleteController extends BaseController
{
    public function __invoke(Release $release)
    {
        $release->delete();
        return redirect()->route('admin.release.index');
    }
}

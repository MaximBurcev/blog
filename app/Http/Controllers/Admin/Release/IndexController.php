<?php

namespace App\Http\Controllers\Admin\Release;

use App\Http\Controllers\Admin\Post\BaseController;
use App\Models\Release;
class IndexController extends BaseController
{
    public function __invoke()
    {
        $releases = Release::all();
        return view('admin.releases.index', compact('releases'));
    }
}

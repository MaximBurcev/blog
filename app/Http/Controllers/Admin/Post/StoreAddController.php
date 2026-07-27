<?php

namespace App\Http\Controllers\Admin\Post;

use App\Http\Requests\Admin\Post\StoreAddRequest;
use App\Jobs\StorePostJob;

class StoreAddController extends BaseController
{
    public function __invoke(StoreAddRequest $request)
    {
        $data = $request->validated();
        StorePostJob::dispatch($data);

        return redirect()->route('admin.post.index');
    }
}

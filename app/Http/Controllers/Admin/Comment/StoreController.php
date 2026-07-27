<?php

namespace App\Http\Controllers\Admin\Comment;

use App\DataTransferObjects\PostData;
use App\Http\Controllers\Admin\Post\BaseController;
use App\Http\Requests\Admin\Post\StoreRequest;

class StoreController extends BaseController
{
    public function __invoke(StoreRequest $request)
    {
        $data = $request->validated();
        $this->service->store(PostData::fromArray($data));

        return redirect()->route('admin.post.index');
    }
}

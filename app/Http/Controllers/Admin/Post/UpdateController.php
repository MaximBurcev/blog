<?php

namespace App\Http\Controllers\Admin\Post;

use App\DataTransferObjects\PostData;
use App\Http\Requests\Admin\Post\UpdateRequest;
use App\Models\Post;

class UpdateController extends BaseController
{
    public function __invoke(UpdateRequest $request, Post $post)
    {
        $data = $request->validated();
        $post = $this->service->update(PostData::fromArray($data), $post);

        return redirect()->route('admin.post.edit', compact('post'));
    }
}

<?php

namespace App\Http\Controllers\Admin\Post;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\StoreAddRequest;
use App\Jobs\StorePostJob;
use App\Models\Post;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use Stichoza\GoogleTranslate\GoogleTranslate;

class StoreAddController extends BaseController
{
    public function __invoke(StoreAddRequest $request)
    {
        $data = $request->validated();
        StorePostJob::dispatch($data);
        return redirect()->route('admin.post.index');
    }

}

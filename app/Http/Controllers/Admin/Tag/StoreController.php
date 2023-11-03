<?php

namespace App\Http\Controllers\Admin\Tag;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Tag\StoreRequest;
use App\Models\Tag;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    public function __invoke(StoreRequest $request)
    {
        dd($request);
        $data = $request->validated();
        $data['code'] = Str::slug($data['title']);
        dd($data);
        Tag::firstOrCreate($data);

        return redirect()->route('admin.tag.index');
    }
}

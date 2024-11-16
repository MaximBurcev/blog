<?php

namespace App\Http\Controllers\Admin\Release;

use App\Http\Controllers\Admin\Release\BaseController;
use App\Http\Requests\Admin\Release\StoreRequest;

class StoreController extends BaseController
{
    public function __invoke(StoreRequest $request)
    {
        dd($request);

        $data = $request->validated();
        $this->service->store($data);

        return redirect()->route('admin.release.index');
    }
}

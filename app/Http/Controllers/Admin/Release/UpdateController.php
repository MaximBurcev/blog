<?php

namespace App\Http\Controllers\Admin\Release;

use App\Http\Requests\Admin\Release\UpdateRequest;
use App\Models\Release;
use App\Service\ReleaseService;
use Illuminate\Http\RedirectResponse;

class UpdateController extends BaseController
{
    public function __construct(
        public ReleaseService $service
    ) {}

    public function __invoke(UpdateRequest $request, Release $release): RedirectResponse
    {
        $data = $request->validated();
        $this->service->update($data, $release);

        return redirect()
            ->route('admin.release.index')
            ->with('success', 'Релиз успешно обновлён');
    }
}

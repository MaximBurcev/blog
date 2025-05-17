<?php

namespace App\Http\Controllers\Admin\Release;

use App\Http\Controllers\Admin\Release\BaseController;
use App\Http\Requests\Admin\Release\StoreRequest;
use App\Jobs\ParseLinksJob;
use App\Service\ReleaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class StoreController extends BaseController
{

    public function __construct(
        public ReleaseService $service
    )
    {

    }

    public function __invoke(StoreRequest $request): RedirectResponse
    {
        try {
            $data = $request->validated();
            $release = $this->service->store($data);

            $this->service->addPosts($release->url);

            return redirect()
                ->route('admin.release.index')
                ->with('success', 'Релиз успешно создан');
        } catch (\Exception $e) {
            Log::error('Ошибка при создании релиза: ' . $e->getMessage(), [
                'url'   => $request->url,
                'error' => $e->getMessage()
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Произошла ошибка при создании релиза');
        }
    }
}

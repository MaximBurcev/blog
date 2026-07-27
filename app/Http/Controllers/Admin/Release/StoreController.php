<?php

namespace App\Http\Controllers\Admin\Release;

use App\Http\Requests\Admin\Release\StoreRequest;
use App\Jobs\ParseReleaseJob;
use App\Service\ReleaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class StoreController extends BaseController
{
    public function __construct(
        public ReleaseService $service
    ) {}

    public function __invoke(StoreRequest $request): RedirectResponse
    {
        try {
            $data = $request->validated();
            $release = $this->service->store($data);

            ParseReleaseJob::dispatch($release->url);

            return redirect()
                ->route('admin.release.index')
                ->with('success', 'Релиз создан, посты добавляются в фоне');
        } catch (\Exception $e) {
            Log::error('Ошибка при создании релиза: '.$e->getMessage(), [
                'url' => $request->url,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Произошла ошибка при создании релиза');
        }
    }
}

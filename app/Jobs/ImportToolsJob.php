<?php

namespace App\Jobs;

use App\Service\ToolImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportToolsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(private readonly string $url) {}

    public function uniqueId(): string
    {
        return md5($this->url);
    }

    public function handle(ToolImportService $service): void
    {
        $service->importFromDigest($this->url);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ImportToolsJob failed', [
            'url' => $this->url,
            'error' => $exception->getMessage(),
        ]);
    }
}

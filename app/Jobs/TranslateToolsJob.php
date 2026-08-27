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

class TranslateToolsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(private readonly int $limit = 50) {}

    public function uniqueId(): string
    {
        return 'tools-translate';
    }

    public function handle(ToolImportService $service): void
    {
        $service->translatePending($this->limit);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('TranslateToolsJob failed', ['error' => $exception->getMessage()]);
    }
}

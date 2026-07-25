<?php

namespace App\Jobs;

use App\Service\ReleaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ReleaseService::addPosts() делает синхронный внешний HTTP-фетч (до 30с
 * таймаута) и парсинг DOM всей страницы дайджеста. Раньше это выполнялось
 * прямо в цикле запроса Admin\Release\StoreController — при недоступном
 * источнике админ ждал таймаут и получал 500. Вынесено в очередь.
 */
class ParseReleaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public function __construct(private readonly string $url)
    {
    }

    public function handle(ReleaseService $service): void
    {
        $service->addPosts($this->url);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ParseReleaseJob failed', [
            'url' => $this->url,
            'error' => $exception->getMessage(),
        ]);
    }
}

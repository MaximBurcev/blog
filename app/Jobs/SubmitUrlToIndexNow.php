<?php

namespace App\Jobs;

use App\Service\IndexNowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Пинг IndexNow при публикации поста. Уходит в очередь, а не выполняется в
 * запросе админки: внешний HTTP-вызов не должен тормозить сохранение.
 */
class SubmitUrlToIndexNow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $url) {}

    public function handle(IndexNowService $indexNow): void
    {
        $indexNow->submit([$this->url]);
    }
}

<?php

namespace App\Jobs;

use App\Service\ReleaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class ParseReleaseJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    /**
     * Чуть больше полного цикла попыток (60с × 2), чтобы залипший лок не
     * блокировал повторный разбор надолго при убитом воркере.
     */
    public int $uniqueFor = 300;

    public function __construct(private readonly string $url) {}

    /**
     * Дедуп по url: двойное нажатие «Спарсить» в ReleaseResource ставило два
     * одинаковых разбора. Дублей постов не будет (UNIQUE на posts.url), но
     * внешние фетчи и переводы удваивались впустую.
     */
    public function uniqueId(): string
    {
        return md5($this->url);
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

<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * Список берём из config/security.php (env TRUSTED_PROXIES), а не из
     * захардкоженного свойства: за балансировщиком/CDN без него схема запроса
     * определяется как http — HSTS не выставляется, secure-cookie считаются
     * небезопасными, а в post_views пишется IP прокси вместо посетителя.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $proxies = config('security.trusted_proxies', []);

        if ($proxies === ['*']) {
            return '*';
        }

        return $proxies ?: null;
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}

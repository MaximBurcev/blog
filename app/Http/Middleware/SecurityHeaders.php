<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Базовые security-заголовки (security-audit-2026-07-24 MAJ-4) —
 * ни один из них раньше не выставлялся вообще.
 *
 * Про CSP честно: 'unsafe-inline'/'unsafe-eval' в script-src обязательны,
 * пока в шаблонах есть инлайновые <script> (AdminLTE, layouts/main,
 * post/show), а Livewire и Alpine (в том числе внутри Filament) вычисляют
 * выражения через new Function. То есть инлайновую инъекцию эта политика
 * НЕ ловит — от неё защищает HtmlSanitizerService. Что политика реально
 * закрывает: подгрузку скрипта с чужого хоста (<script src="//evil/">),
 * отправку формы наружу (form-action), подмену базового URL (<base>),
 * object/embed-плагины и фрейминг страницы (frame-ancestors).
 *
 * Ужесточать (nonce на каждый инлайн-скрипт, отказ от unsafe-eval) имеет
 * смысл после обкатки с CSP_REPORT_ONLY=true.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // На http заголовок игнорируется браузером — выставлять его там незачем
        if ($request->secure() && config('security.hsts.enabled')) {
            $response->headers->set('Strict-Transport-Security', $this->hsts());
        }

        if (config('security.csp.enabled')) {
            $response->headers->set(
                config('security.csp.report_only')
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy',
                $this->csp($request)
            );
        }

        return $response;
    }

    private function hsts(): string
    {
        $header = 'max-age='.(int) config('security.hsts.max_age');

        if (config('security.hsts.include_subdomains')) {
            $header .= '; includeSubDomains';
        }

        if (config('security.hsts.preload')) {
            $header .= '; preload';
        }

        return $header;
    }

    private function csp(Request $request): string
    {
        $extra = (array) config('security.csp.extra_hosts', []);
        // Dev-сервер Vite отдаёт модули со своего порта и держит там же
        // HMR-сокет — без этих источников `npm run dev` умрёт под CSP
        $vite = Vite::isRunningHot() ? [$this->viteOrigin(), $this->viteOrigin('ws')] : [];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => array_merge(
                ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://cdnjs.cloudflare.com', 'https://cdn.jsdelivr.net'],
                $vite,
                $extra
            ),
            'style-src' => array_merge(
                ["'self'", "'unsafe-inline'", 'https://cdnjs.cloudflare.com', 'https://cdn.jsdelivr.net', 'https://fonts.googleapis.com', 'https://fonts.bunny.net'],
                $vite,
                $extra
            ),
            'font-src' => array_merge(
                ["'self'", 'data:', 'https://fonts.gstatic.com', 'https://fonts.bunny.net', 'https://cdnjs.cloudflare.com', 'https://cdn.jsdelivr.net'],
                $extra
            ),
            // В контенте старых постов остались ссылки на внешние картинки
            // (ContentImageService скачивает не всё), а картинка сама по себе
            // не исполняемый вектор
            'img-src' => array_merge(["'self'", 'data:', 'https:'], $extra),
            'connect-src' => array_merge(["'self'"], $this->reverbOrigins(), $vite, $extra),
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        if ($request->secure()) {
            $directives['upgrade-insecure-requests'] = [];
        }

        $policy = [];
        foreach ($directives as $directive => $sources) {
            $policy[] = trim($directive.' '.implode(' ', array_unique($sources)));
        }

        if ($reportUri = config('security.csp.report_uri')) {
            $policy[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $policy);
    }

    /**
     * WebSocket Reverb — фронт (resources/js/bootstrap.js) держит с ним
     * постоянное соединение, под connect-src 'self' оно бы рвалось.
     */
    private function reverbOrigins(): array
    {
        $options = (array) config('broadcasting.connections.reverb.options', []);
        $host = $options['host'] ?? null;

        if (! $host) {
            return [];
        }

        $authority = $host.(($options['port'] ?? null) ? ':'.$options['port'] : '');
        $secure = ($options['scheme'] ?? 'https') === 'https';

        return [
            ($secure ? 'wss' : 'ws').'://'.$authority,
            ($secure ? 'https' : 'http').'://'.$authority,
        ];
    }

    private function viteOrigin(string $protocol = 'http'): string
    {
        $parts = parse_url(Vite::asset(''));
        $secure = ($parts['scheme'] ?? 'http') === 'https';
        $scheme = $protocol === 'ws'
            ? ($secure ? 'wss' : 'ws')
            : ($secure ? 'https' : 'http');

        return $scheme.'://'.($parts['host'] ?? 'localhost').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}

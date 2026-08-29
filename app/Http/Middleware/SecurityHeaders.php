<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Базовые security-заголовки (security-audit-2026-07-24 MAJ-4) —
 * ни один из них раньше не выставлялся вообще.
 *
 * Про CSP: на публичных страницах политика строгая — инлайновые скрипты
 * разрешены только по nonce, 'unsafe-inline' и 'unsafe-eval' в script-src
 * нет. Своих инлайнов там несколько (layouts/main; post/show — подсветка
 * кода, лайк, кнопка копирования ссылки, ответы на комментарии; JSON-LD),
 * все получают nonce из этого мидлваря.
 *
 * Панель администратора живёт по ослабленной политике: Filament и Livewire
 * вставляют собственные инлайновые скрипты без nonce, а Alpine вычисляет
 * выражения через new Function, то есть требует 'unsafe-eval'. Ужесточение
 * там упирается в чужой код и делается отдельно.
 *
 * Что политика закрывает и на публичной части, и в панели: подгрузку скрипта
 * с чужого хоста (<script src="//evil/">), отправку формы наружу
 * (form-action), подмену базового URL (<base>), object/embed-плагины и
 * фрейминг страницы (frame-ancestors). На публичных страницах теперь ещё и
 * исполнение внедрённого в разметку скрипта.
 */
class SecurityHeaders
{
    /**
     * Пути под ослабленной политикой — те, где страницу собирает чужой код
     * (Filament, Livewire, log-viewer, Telescope): инлайновые скрипты там без
     * nonce, а превью загруженного файла рисуется из blob:.
     *
     * Константа управляет сразу тремя директивами — script-src, img-src и
     * worker-src: добавляя сюда путь ради скриптов, вы заодно разрешаете там
     * blob:-картинки и blob:-воркеры.
     */
    private const RELAXED_PATHS = ['filament', 'filament/*', 'livewire/*', 'log-viewer', 'log-viewer/*', 'telescope', 'telescope/*'];

    public function handle(Request $request, Closure $next): Response
    {
        // Нонс должен существовать ДО рендера шаблонов, поэтому генерируем и
        // раздаём его до $next(): заголовок выставляется потом, но значение
        // уже то же самое, что попало в разметку.
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

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

    /**
     * Чем разрешаем инлайновые скрипты: нонсом (публичная часть) или
     * ослаблением под чужой код (панель, dev-сервер Vite с его инжектами).
     *
     * @return array<int, string>
     */
    private function scriptSourceMode(Request $request, bool $relaxed): array
    {
        if ($relaxed || Vite::isRunningHot()) {
            return ["'unsafe-inline'", "'unsafe-eval'"];
        }

        return ["'nonce-".$request->attributes->get('csp_nonce')."'"];
    }

    private function csp(Request $request): string
    {
        $relaxed = $request->is(self::RELAXED_PATHS);
        $extra = (array) config('security.csp.extra_hosts', []);
        // Dev-сервер Vite отдаёт модули со своего порта и держит там же
        // HMR-сокет — без этих источников `npm run dev` умрёт под CSP
        $vite = Vite::isRunningHot() ? [$this->viteOrigin(), $this->viteOrigin('ws')] : [];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => array_merge(
                ["'self'", 'https://cdnjs.cloudflare.com', 'https://cdn.jsdelivr.net'],
                $this->scriptSourceMode($request, $relaxed),
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
            // не исполняемый вектор.
            //
            // blob: — только на ослабленных путях. FilePond (поля
            // «Превью-изображение» и «Главное изображение» в Filament)
            // скачивает уже сохранённый файл, заворачивает его в Blob и
            // рисует превью из blob:-URL, а не по адресу файла; без этого
            // источника поле показывает одну строку с именем файла, и правка
            // поста выглядит как потеря картинки. Публичным страницам blob:
            // не нужен — там все изображения приходят по http(s).
            'img-src' => array_merge(["'self'", 'data:', 'https:'], $relaxed ? ['blob:'] : [], $extra),
            'connect-src' => array_merge(["'self'"], $this->reverbOrigins(), $vite, $extra),
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        // Вторая половина того же превью: саму картинку FilePond декодирует и
        // рисует в Web Worker, собранном из blob:. Своей директивы у воркеров
        // не было, они падали в script-src, где blob: нет — и поле разворачивало
        // пустую серую панель нужного размера вместо картинки. На публичной
        // части директива по-прежнему не выставляется: воркеров там нет вовсе,
        // и наследование от script-src остаётся строже.
        // $extra здесь тоже нужен: до появления этой директивы воркеры
        // наследовали script-src вместе с CSP_EXTRA_HOSTS, и без него
        // задокументированная отдушина для новых хостов молча перестала бы
        // покрывать воркеры.
        if ($relaxed) {
            $directives['worker-src'] = array_merge(["'self'", 'blob:'], $extra);
        }

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

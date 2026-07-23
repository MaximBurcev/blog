<?php

namespace App\Support;

/**
 * Проверяет URL перед любым исходящим fetch во внешний источник
 * (StorePostJob, ReleaseService, ContentImageService) — защита от SSRF.
 *
 * URL в этом пайплайне не вводятся напрямую пользователем, а извлекаются
 * из HTML стороннего сайта (ссылки в дайджесте, og:image, src картинок),
 * поэтому это классический confused deputy: сервер по своей воле идёт
 * туда, куда его привела чужая страница — в том числе через 3xx-редирект.
 * Проверка должна выполняться перед КАЖДЫМ хопом, а не только перед
 * исходным URL.
 */
class UrlSafetyChecker
{
    public function isSafe(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (! $this->domainAllowed($host)) {
            return false;
        }

        return $this->resolvesToPublicIp($host);
    }

    private function domainAllowed(string $host): bool
    {
        foreach (config('releases.blocked_domains', []) as $domain) {
            if ($domain !== '' && str_contains($host, $domain)) {
                return false;
            }
        }

        $allowList = config('releases.allowed_domains', []);
        if (empty($allowList)) {
            return true;
        }

        foreach ($allowList as $domain) {
            if ($domain !== '' && str_contains($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Отсекает loopback/приватные/link-local/reserved адреса (127/8, 10/8,
     * 172.16/12, 192.168/16, 169.254/16 — метаданные облака, ::1, fc00::/7
     * и т.п.), чтобы редирект или DNS-резолв хоста не увёл fetch во
     * внутреннюю сеть.
     */
    private function resolvesToPublicIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $ip = gethostbyname($host);
            if ($ip === $host) {
                return false; // резолв не удался
            }
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}

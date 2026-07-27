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
 *
 * resolvePublicIp() возвращает конкретный IP, который нужно закрепить в
 * HTTP-клиенте (curl --resolve / CURLOPT_RESOLVE), а не резолвить хост
 * заново при самом запросе — иначе между проверкой и fetch'ем DNS-запись
 * может смениться на приватный адрес (DNS rebinding, TOCTOU).
 */
class UrlSafetyChecker
{
    public function resolvePublicIp(string $url): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $host = $this->normalizeHost($host);

        if (! $this->domainAllowed($host)) {
            return null;
        }

        return $this->resolveAndValidate($host);
    }

    /**
     * Приводит host к канонической форме перед сравнением с блок/allow-листом
     * и резолвингом: снимает завершающую точку FQDN-корня (`facebook.com.`
     * иначе не матчился бы со строкой `facebook.com` в blocked_domains — то
     * же самое доменное имя, синтаксически другая строка) и переводит IDN
     * в ASCII/punycode, чтобы разные представления одного домена сравнивались
     * одинаково.
     */
    private function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }

        return $host;
    }

    private function domainAllowed(string $host): bool
    {
        foreach (config('releases.blocked_domains', []) as $domain) {
            if ($domain !== '' && $this->hostMatchesDomain($host, $domain)) {
                return false;
            }
        }

        $allowList = config('releases.allowed_domains', []);
        if (empty($allowList)) {
            return true;
        }

        foreach ($allowList as $domain) {
            if ($domain !== '' && $this->hostMatchesDomain($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Точное совпадение хоста с доменом либо поддомен с точкой-границей —
     * substring-матчинг (str_contains) пропускал бы 'evil.com' для домена
     * 'medium.com' как 'medium.com.evil.com', а блок-лист 'facebook.com'
     * обходился бы поддоменом 'facebook.com.evil.com'.
     */
    private function hostMatchesDomain(string $host, string $domain): bool
    {
        $host = strtolower($host);
        $domain = strtolower(ltrim($domain, '.'));

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }

    /**
     * Резолвит хост и проверяет ВСЕ A/AAAA-записи (не только первую) —
     * если хотя бы одна указывает на приватный/loopback/link-local/reserved
     * адрес (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16 — метаданные
     * облака, ::1, fc00::/7 и т.п.), хост целиком отклоняется.
     */
    private function resolveAndValidate(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? $host : null;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (empty($records)) {
            return null;
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip !== null) {
                $ips[] = $ip;
            }
        }

        if (empty($ips)) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * Диапазоны, которые filter_var(FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE)
     * не считает приватными/зарезервированными, но которые ведут на
     * внутреннюю инфраструктуру: CGNAT (RFC 6598 — на нём отдаются метаданные
     * Alibaba Cloud на 100.100.100.200), IETF protocol assignments,
     * 6to4 relay anycast и NAT64 well-known prefix.
     */
    private const EXTRA_BLOCKED_CIDRS = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '64:ff9b::/96',
    ];

    private function isPublicIp(string $ip): bool
    {
        $ip = $this->unwrapIpv4MappedIpv6($ip) ?? $ip;

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_CIDRS as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $remainderBits) & 0xFF);

        return (substr($ipBin, $bytes, 1) & $mask) === (substr($subnetBin, $bytes, 1) & $mask);
    }

    /**
     * filter_var() с FILTER_FLAG_NO_PRIV_RANGE не разворачивает
     * IPv4-mapped/compatible IPv6-литералы (::ffff:169.254.169.254) во
     * вложенный IPv4 перед проверкой диапазона, поэтому такой адрес
     * проходил бы как "публичный" и позволял обратиться к cloud metadata
     * (169.254.169.254) или loopback в обход всей проверки.
     */
    private function unwrapIpv4MappedIpv6(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        if (substr($packed, 0, 12) !== "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return null;
        }

        return inet_ntop(substr($packed, 12));
    }
}

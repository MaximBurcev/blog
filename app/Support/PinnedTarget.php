<?php

namespace App\Support;

/**
 * Результат проверки URL в UrlSafetyChecker: канонизированный URL плюс IP,
 * к которому фетчер обязан прикрепить соединение.
 *
 * Существует потому, что одного IP недостаточно: пиннинг держится на том,
 * что запись --resolve / CURLOPT_RESOLVE смэтчится с хостом, который HTTP-клиент
 * возьмёт из URL. Проверка резолвит host через parse_url, а соединение
 * устанавливает libcurl со своим парсером/нормализацией — при расхождении
 * (trailing dot, IDN, регистр) запись пиннинга молча не совпадает, curl делает
 * собственный live-DNS-резолв, и окно DNS rebinding/TOCTOU, ради закрытия
 * которого пиннинг и делался, открывается заново.
 *
 * Поэтому объект отдаёт URL уже с нормализованным хостом (клиент видит ровно
 * то, что проверялось) и ipMatches() — постфактум-верификацию фактического
 * адреса соединения, чтобы несовпадение приводило к отказу, а не к тихому
 * походу «куда получилось».
 */
final class PinnedTarget
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
        public readonly bool $hostIsIpLiteral,
    ) {}

    /**
     * Строка для curl --resolve / CURLOPT_RESOLVE. null — когда host уже
     * является IP-литералом: DNS в таком запросе не участвует, подменять
     * между проверкой и соединением нечего.
     */
    public function resolveEntry(): ?string
    {
        if ($this->hostIsIpLiteral) {
            return null;
        }

        return $this->host.':'.$this->port.':'.$this->ip;
    }

    /**
     * Совпадает ли адрес, к которому фактически подключился HTTP-клиент,
     * с закреплённым. Сравнение по бинарному представлению: один и тот же
     * адрес приходит в разной записи (2001:db8::1 / 2001:0db8:0:0:0:0:0:1,
     * 8.8.8.8 / ::ffff:8.8.8.8 при dual-stack сокете).
     */
    public function ipMatches(string $actual): bool
    {
        $pinned = self::packed($this->ip);
        $connected = self::packed($actual);

        return $pinned !== null && $pinned === $connected;
    }

    private static function packed(string $ip): ?string
    {
        $binary = @inet_pton(trim($ip, '[]'));

        if ($binary === false) {
            return null;
        }

        // IPv4-mapped IPv6 (::ffff:x.x.x.x) — тот же адрес, что вложенный IPv4
        if (strlen($binary) === 16 && str_starts_with($binary, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $binary = substr($binary, 12);
        }

        return $binary;
    }
}

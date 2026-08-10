<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

/**
 * Абсолютные URL Laravel строит от заголовка Host, а он приходит от клиента:
 * без этого мидлваря `Host: evil.tld` на POST /password/email отправлял жертве
 * письмо с нашего адреса, но со ссылкой на чужой домен — то есть отдавал токен
 * сброса атакующему.
 */
class TrustHosts extends Middleware
{
    /**
     * Явный список, а не allSubdomainsOfApplicationUrl().
     *
     * Тот отдаёт паттерн `^(.+\.)?maxburcev\.ru$`, то есть доверяет ЛЮБОМУ
     * поддомену. Поддомены у сайта не используются, а перехваченный поддомен
     * (wildcard-DNS, брошенная запись у внешнего сервиса) снова открывал бы
     * отравление ссылки сброса — ровно то, ради чего мидлварь и включали.
     *
     * Хост берётся из APP_URL; www-вариант добавляем отдельно, потому что он
     * реально может прийти от посетителя, а прочие поддомены — нет.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            // APP_URL не задан или битый. Запретить всё нельзя — приложение
            // перестало бы отвечать вообще, поэтому откатываемся к прежнему
            // поведению вместо отказа.
            return [$this->allSubdomainsOfApplicationUrl()];
        }

        $host = strtolower($host);

        return array_values(array_unique([
            $host,
            str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host,
        ]));
    }
}

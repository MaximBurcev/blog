<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Мидлварь закрывает отравление заголовка Host: без неё `Host: evil.tld` на
 * POST /password/email отправлял жертве письмо с нашего адреса, но со ссылкой
 * на чужой домен, то есть отдавал токен сброса атакующему.
 *
 * Проверяем именно hosts(), а не поведение запроса: shouldSpecifyTrustedHosts()
 * возвращает false в local и под тестами, поэтому feature-тест на отравление
 * Host был бы ложно-зелёным всегда.
 */
class TrustHostsTest extends TestCase
{
    private function hosts(string $appUrl): array
    {
        Config::set('app.url', $appUrl);

        return (new TrustHosts($this->app))->hosts();
    }

    public function test_trusts_bare_domain_and_www(): void
    {
        $this->assertSame(
            ['maxburcev.ru', 'www.maxburcev.ru'],
            $this->hosts('https://maxburcev.ru')
        );
    }

    /**
     * Ключевое отличие от allSubdomainsOfApplicationUrl(): тот отдаёт паттерн
     * `^(.+\.)?maxburcev\.ru$` и доверяет ЛЮБОМУ поддомену, включая
     * перехваченный через wildcard-DNS или брошенную запись.
     */
    public function test_does_not_trust_arbitrary_subdomains(): void
    {
        $hosts = $this->hosts('https://maxburcev.ru');

        $this->assertNotContains('evil.maxburcev.ru', $hosts);

        foreach ($hosts as $pattern) {
            $this->assertSame(
                0,
                preg_match('{^'.$pattern.'$}i', 'evil.maxburcev.ru'),
                'Поддомен evil.maxburcev.ru не должен подходить под '.$pattern
            );
        }
    }

    public function test_www_in_app_url_yields_both_forms(): void
    {
        $this->assertSame(
            ['www.example.com', 'example.com'],
            $this->hosts('https://www.example.com')
        );
    }

    /**
     * Битый APP_URL не должен ронять приложение целиком — откатываемся к
     * прежнему поведению, а не запрещаем все хосты.
     */
    public function test_falls_back_when_app_url_is_unusable(): void
    {
        $hosts = $this->hosts('');

        $this->assertCount(1, $hosts);
        $this->assertNotSame([], $hosts);
    }
}

<?php

namespace Tests\Unit;

use App\Support\BotDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BotDetectorTest extends TestCase
{
    #[DataProvider('bots')]
    public function test_known_automated_clients_are_detected(string $userAgent): void
    {
        $this->assertTrue(BotDetector::isBot($userAgent), $userAgent);
    }

    #[DataProvider('humans')]
    public function test_browsers_are_not_detected_as_bots(string $userAgent): void
    {
        $this->assertFalse(BotDetector::isBot($userAgent), $userAgent);
    }

    public function test_missing_user_agent_is_a_bot(): void
    {
        // Браузер всегда представляется. Пустой UA — скрипт.
        $this->assertTrue(BotDetector::isBot(null));
        $this->assertTrue(BotDetector::isBot(''));
        $this->assertTrue(BotDetector::isBot('   '));
    }

    public function test_cubot_phone_is_not_a_bot(): void
    {
        // Марка смартфонов, попадающая под подстроку 'bot'. Требовать границу
        // слова нельзя — в 'Googlebot' перед 'bot' стоит буква, поэтому
        // исключение вырезается из строки до поиска сигнатур.
        $this->assertFalse(BotDetector::isBot(
            'Mozilla/5.0 (Linux; Android 11; CUBOT_NOTE_20) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.71 Mobile Safari/537.36'
        ));
    }

    public function test_detection_is_case_insensitive(): void
    {
        $this->assertTrue(BotDetector::isBot('GOOGLEBOT/2.1'));
        $this->assertTrue(BotDetector::isBot('CuRl/8.4.0'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bots(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'yandexbot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'semrush' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
            'gptbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.0; +https://openai.com/gptbot'],
            'ccbot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)'],
            'telegram' => ['TelegramBot (like TwitterBot)'],
            'facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'curl' => ['curl/8.4.0'],
            'wget' => ['Wget/1.21.3'],
            'python' => ['python-requests/2.31.0'],
            'go' => ['Go-http-client/2.0'],
            'java' => ['Java/17.0.8'],
            'guzzle' => ['GuzzleHttp/7'],
            'headless' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36'],
            'feedly' => ['Feedly/1.0 (+http://www.feedly.com/fetcher.html; like FeedFetcher-Google)'],
            'uptime' => ['Uptime-Kuma/1.23.0'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function humans(): array
    {
        return [
            'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0'],
            'safari ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1'],
            'yandex browser' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 YaBrowser/23.11.0.0 Safari/537.36'],
            'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'],
            'android chrome' => ['Mozilla/5.0 (Linux; Android 13; SM-S908B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'],
        ];
    }
}

<?php

namespace App\Support;

/**
 * Робот это или читатель — по User-Agent.
 *
 * Единственный доступный признак: обратный DNS дорог на каждый просмотр, а
 * поведенческие эвристики (частота, отсутствие cookie) требуют состояния,
 * которого у нас нет в момент записи.
 *
 * Метод заведомо неточен в обе стороны: робот вправе представиться браузером,
 * а редкий читатель — пустым UA. Поэтому вывод пишется отдельным флагом и не
 * влияет на то, сохранится запись или нет: ошибку детектора можно пересмотреть
 * задним числом, потерянную строку — нет.
 */
final class BotDetector
{
    /**
     * Токены в нижнем регистре, встречающиеся в UA автоматических клиентов.
     *
     * Проверяются подстрокой, а не по границе слова: краулеры пишут себя как
     * 'Googlebot/2.1', 'AhrefsBot/7.0', 'python-requests/2.31' — форма записи
     * произвольная, устойчива только сама подстрока.
     *
     * Список закрывает то, что реально ходит по сайту: поисковики, SEO-краулеры,
     * сборщики обучающих корпусов, разворачиватели ссылок в мессенджерах,
     * мониторинг и голые HTTP-клиенты.
     */
    private const SIGNATURES = [
        // Общие самоназвания
        'bot', 'crawler', 'spider', 'crawl', 'slurp', 'scraper', 'archiver',
        // Поисковые и SEO
        'ahrefs', 'semrush', 'mj12', 'dotbot', 'dataprovider', 'blexbot',
        'serpstat', 'megaindex', 'sistrix', 'screaming frog',
        // Сборщики корпусов для моделей
        'gptbot', 'ccbot', 'claude', 'anthropic', 'perplexity', 'youbot',
        'diffbot', 'omgili', 'timpibot', 'imagesiftbot',
        // Разворачиватели ссылок в мессенджерах и соцсетях
        'facebookexternalhit', 'whatsapp', 'telegram', 'skypeuripreview',
        'vkshare', 'twitterbot', 'slackbot', 'discordbot', 'embedly',
        'linkedinbot', 'pinterest', 'redditbot', 'quora link preview',
        // Мониторинг и проверки доступности
        'uptime', 'pingdom', 'statuscake', 'site24x7', 'newrelicpinger',
        'monitoring', 'nagios', 'zabbix',
        // Голые HTTP-клиенты и headless-браузеры
        'curl/', 'wget', 'python-requests', 'python-urllib', 'aiohttp',
        'httpx', 'go-http-client', 'okhttp', 'java/', 'apache-httpclient',
        'guzzlehttp', 'libwww-perl', 'lwp::simple', 'phantomjs', 'headlesschrome',
        'puppeteer', 'playwright', 'selenium', 'scrapy', 'node-fetch', 'axios',
        // Читалки и агрегаторы
        'feedly', 'feedfetcher', 'inoreader', 'newsblur', 'rss', 'feedburner',
    ];

    /**
     * Подстроки, которые сами содержат сигнатуру, но роботами не являются.
     *
     * Cubot — марка android-смартфонов, их модель попадает в UA как
     * 'CUBOT_NOTE_20' и ловится подстрокой 'bot'. Требовать границу слова
     * нельзя: в 'Googlebot' перед 'bot' стоит буква. Поэтому исключения
     * вырезаются из строки до поиска сигнатур.
     */
    private const EXCLUSIONS = ['cubot'];

    public static function isBot(?string $userAgent): bool
    {
        $userAgent = trim((string) $userAgent);

        // Браузер без User-Agent не ходит: заголовок шлют все и всегда.
        // Пустое значение — это скрипт, который не потрудился представиться.
        if ($userAgent === '') {
            return true;
        }

        $needle = str_replace(self::EXCLUSIONS, '', mb_strtolower($userAgent));

        foreach (self::SIGNATURES as $signature) {
            if (str_contains($needle, $signature)) {
                return true;
            }
        }

        return false;
    }
}

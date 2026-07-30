<?php

namespace App\Service;

use App\Jobs\StorePostJob;
use App\Models\Release;
use App\Support\ContentSelectorResolver;
use App\Support\UrlSafetyChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class ReleaseService
{
    private const DEFAULT_SELECTOR = 'td.bodyContent a[href]';

    private const DEFAULT_MAX_LINKS = 5;

    private const DEFAULT_TIMEOUT = 30;

    private const MAX_REDIRECTS = 5;

    private array $config;

    public function __construct(
        private readonly UrlSafetyChecker $urlSafetyChecker = new UrlSafetyChecker
    ) {
        $this->config = [
            'selector' => config('releases.parser_selector', self::DEFAULT_SELECTOR),
            'max_links' => (int) config('releases.max_links', self::DEFAULT_MAX_LINKS),
            'timeout' => (int) config('releases.timeout', self::DEFAULT_TIMEOUT),
            'offset' => (int) config('releases.offset', 2),
            'user_agent' => config('releases.user_agent', 'Mozilla/5.0 (compatible; ReleaseParser/1.0)'),
            'enable_job_dispatch' => config('releases.enable_job_dispatch', true),
            'allowed_domains' => config('releases.allowed_domains', []),
            'blocked_domains' => config('releases.blocked_domains', []),
            'section_headings' => config('releases.section_headings', []),
        ];
    }

    public function store(array $data): Release
    {
        $this->validateReleaseData($data);

        return $this->saveRelease($data);
    }

    public function update(array $data, Release $release): Release
    {
        $this->validateReleaseData($data);

        return $this->saveRelease($data, $release);
    }

    private function validateReleaseData(array $data): void
    {
        $validator = Validator::make($data, [
            'url' => 'required|url|max:2048',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function saveRelease(array $data, ?Release $release = null): Release
    {
        try {
            DB::beginTransaction();

            $releaseData = ['url' => rtrim($data['url'], '/')];

            if ($release) {
                $release->update($releaseData);
            } else {
                // firstOrCreate, а не create: релиз идемпотентен по url — повторный
                // release:parse того же дайджеста не плодит дубли (wasRecentlyCreated
                // остаётся корректным для вывода в команде).
                $release = Release::firstOrCreate(['url' => $releaseData['url']]);
            }

            DB::commit();

            return $release;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save release', [
                'url' => $data['url'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function validateUrl(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL provided: {$url}");
        }
    }

    /**
     * Ссылка на релиз (дайджест) заводится вручную в админке, но 3xx-редирект
     * на этой странице уже полностью управляется её хостером — поэтому
     * следование редиректам отключено и каждый хоп ревалидируется через
     * UrlSafetyChecker (защита от SSRF на localhost/внутреннюю сеть/
     * метаданные облака).
     */
    private function fetchHtmlContent(string $url): string
    {
        $client = new Client([
            'timeout' => $this->config['timeout'],
            'headers' => [
                'User-Agent' => $this->config['user_agent'],
            ],
            'allow_redirects' => false,
        ]);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->urlSafetyChecker->resolvePublicIp($url);
            if ($ip === null) {
                throw new \RuntimeException("Blocked unsafe URL: {$url}");
            }

            // IP закрепляется через CURLOPT_RESOLVE вместо повторного резолва
            // хоста Guzzle'ом — иначе между проверкой и запросом DNS-запись
            // могла бы смениться на приватный адрес (rebinding/TOCTOU).
            // CURLOPT_MAXFILESIZE_LARGE защищает от гигантского/бесконечного
            // ответа со страницы релиза (DoS воркера по памяти/диску).
            $response = $client->get($url, [
                'curl' => [
                    CURLOPT_RESOLVE => [$this->hostPort($url).':'.$ip],
                    CURLOPT_MAXFILESIZE_LARGE => (int) config('releases.max_content_length'),
                ],
            ]);
            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));

                continue;
            }

            if ($status !== 200) {
                throw new \RuntimeException("HTTP {$status}: Failed to fetch content");
            }

            $content = $response->getBody()->getContents();

            if (empty($content)) {
                throw new \RuntimeException('Empty content received from URL');
            }

            return $content;
        }

        throw new \RuntimeException('Too many redirects');
    }

    /**
     * "host:port" в формате, который ожидает CURLOPT_RESOLVE.
     */
    private function hostPort(string $url): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);

        return $parts['host'].':'.$port;
    }

    public function addPosts(string $url): array
    {
        $this->validateUrl($url);

        try {
            $html = $this->fetchHtmlContent($url);
            $links = $this->extractLinksWithCrawler($html, $this->getLinkSelectorForUrl($url));

            if (empty($links)) {
                Log::info('No links found on the page', ['url' => $url]);

                return [];
            }

            // Применяем конфигурацию для выбора ссылок
            $processedLinks = $this->processLinks($links);

            Log::info('Processed links for dispatch', [
                'url' => $url,
                'total_found' => count($links),
                'processed' => count($processedLinks),
                'links' => array_map(fn ($l) => $l['url'], $processedLinks),
            ]);

            // Отправляем задачи в очередь
            $this->dispatchJobs($processedLinks);

            return $processedLinks;

        } catch (\Exception $e) {
            Log::error('Failed to add posts from URL', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function extractLinksWithCrawler(string $html, ?string $domainSelector = null): array
    {
        $crawler = new Crawler($html);

        try {
            // Секционная фильтрация по h2-заголовкам (Articles/Tutorials и
            // т.п.) заточена под td.bodyContent-вёрстку mailer.inovica.com.
            // Для доменов с явно настроенным своим селектором ссылок эта
            // логика не подходит и должна быть пропущена целиком
            $sectionHeadings = $this->config['section_headings'];

            if ($domainSelector === null && ! empty($sectionHeadings)) {
                return $this->extractLinksFromSections($crawler, $sectionHeadings);
            }

            $selector = $domainSelector ?? $this->config['selector'];

            $links = $crawler->filter($selector)->each(function (Crawler $node) {
                $text = trim($node->text());
                $url = trim($node->attr('href'));

                if (empty($url) || empty($text)) {
                    return null;
                }

                return ['text' => $text, 'url' => $url];
            });

            return array_filter($links);

        } catch (\Exception $e) {
            Log::error('Failed to extract links with crawler', [
                'selector' => $selector,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function extractLinksFromSections(Crawler $crawler, array $headings): array
    {
        $links = [];

        $crawler->filter('td.bodyContent')->each(function (Crawler $td) use (&$links, $headings) {
            $h2 = $td->filter('h2');
            if ($h2->count() === 0) {
                return;
            }

            $heading = trim($h2->first()->text());
            if (! in_array($heading, $headings)) {
                return;
            }

            $td->filter('a[href]')->each(function (Crawler $node) use (&$links) {
                $text = trim($node->text());
                $url = trim($node->attr('href'));

                if (! empty($url) && ! empty($text)) {
                    $links[] = ['text' => $text, 'url' => $url];
                }
            });
        });

        return $links;
    }

    private function processLinks(array $links): array
    {
        // Применяем смещение (offset) и ограничение (max_links)
        $offset = max(0, $this->config['offset']);
        $maxLinks = max(1, $this->config['max_links']);

        // Убираем дубликаты по URL
        $uniqueLinks = [];
        $seenUrls = [];

        foreach ($links as $link) {
            $url = $link['url'];
            if (! in_array($url, $seenUrls)) {
                $uniqueLinks[] = $link;
                $seenUrls[] = $url;
            }
        }

        // Применяем смещение и ограничение
        return array_slice($uniqueLinks, $offset, $maxLinks);
    }

    private function getSelectorForUrl(string $url): string
    {
        return app(ContentSelectorResolver::class)->resolve($url);
    }

    /**
     * CSS-селектор для извлечения ССЫЛОК со страницы релиза (не контента
     * статьи). 'parser_selector' по умолчанию заточен под табличную
     * email-вёрстку mailer.inovica.com — другие дайджесты (JS Weekly и т.п.)
     * вёрстаны иначе, тут можно переопределить селектор по домену.
     */
    private function getLinkSelectorForUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        foreach (config('releases.parser_selectors_by_domain', []) as $domain => $selector) {
            if (str_contains($host, $domain)) {
                return $selector;
            }
        }

        return null;
    }

    private function dispatchJobs(array $links): void
    {
        if (! $this->config['enable_job_dispatch']) {
            Log::info('Job dispatch is disabled', ['links_count' => count($links)]);

            return;
        }

        foreach ($links as $link) {
            try {
                StorePostJob::dispatch([
                    'url' => $link['url'],
                    'selector' => $this->getSelectorForUrl($link['url']),
                    'tag_ids' => [],
                    'translate' => null,
                    // Текст ссылки в дайджесте — запасной заголовок, если
                    // страницу статьи разобрать не удастся (пост всё равно
                    // будет создан, с причиной сбоя).
                    'link_text' => $link['text'] ?? null,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to dispatch job for link', [
                    'url' => $link['url'],
                    'error' => $e->getMessage(),
                ]);
                // Продолжаем обработку других ссылок
            }
        }
    }
}

<?php

namespace App\Service;

use App\Jobs\StorePostJob;
use App\Models\Release;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\DomCrawler\Crawler;
use InvalidArgumentException;

class ReleaseService
{
    private const DEFAULT_SELECTOR = 'td.bodyContent a[href]';
    private const DEFAULT_MAX_LINKS = 5;
    private const DEFAULT_TIMEOUT = 30;
    
    private array $config;
    
    public function __construct()
    {
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
            'url' => 'required|url|max:2048'
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
                $release = Release::create($releaseData);
            }
            
            DB::commit();
            
            return $release;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save release', [
                'url' => $data['url'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function parsePostsUrl(string $url): array
    {
        $this->validateUrl($url);
        
        try {
            $html = $this->fetchHtmlContent($url);
            return $this->extractLinksFromHtml($html);
        } catch (\Exception $e) {
            Log::error('Failed to parse posts from URL', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    private function validateUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL provided: {$url}");
        }
    }
    
    private function fetchHtmlContent(string $url): string
    {
        $client = new Client([
            'timeout' => $this->config['timeout'],
            'headers' => [
                'User-Agent' => $this->config['user_agent']
            ]
        ]);
        
        $response = $client->get($url);
        
        if ($response->getStatusCode() !== 200) {
            throw new RequestException("HTTP {$response->getStatusCode()}: Failed to fetch content");
        }
        
        $content = $response->getBody()->getContents();
        
        if (empty($content)) {
            throw new RequestException('Empty content received from URL');
        }
        
        return $content;
    }
    
    private function extractLinksFromHtml(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        
        if (!$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            throw new InvalidArgumentException('Failed to parse HTML content');
        }
        
        libxml_clear_errors();
        
        $xpath = new \DOMXPath($dom);
        $linkNodes = $xpath->query('//a[@href]');
        
        $links = [];
        foreach ($linkNodes as $linkNode) {
            $href = $linkNode->getAttribute('href');
            $text = trim($linkNode->textContent);
            
            if ($this->isValidLink($href, $text)) {
                $links[] = [
                    'url' => $this->resolveUrl($href, $dom),
                    'title' => $text ?: 'Untitled',
                    'selector' => '.content'
                ];
            }
        }
        
        return $links;
    }
    
    private function isValidLink(string $href, string $text): bool
    {
        // Пропускаем пустые ссылки, якоря, javascript и mailto
        if (empty($href) || 
            str_starts_with($href, '#') || 
            str_starts_with($href, 'javascript:') || 
            str_starts_with($href, 'mailto:') ||
            str_starts_with($href, 'tel:')) {
            return false;
        }
        
        // Проверяем, что ссылка содержит текст или это не просто символ
        if (empty($text) || strlen($text) < 2) {
            return false;
        }
        
        // Проверяем домен, если указаны ограничения
        if (!$this->isDomainAllowed($href)) {
            return false;
        }
        
        return true;
    }
    
    private function isDomainAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        
        // Проверяем заблокированные домены
        foreach ($this->config['blocked_domains'] as $domain) {
            if (str_contains($host, $domain)) {
                return false;
            }
        }
        
        // Если есть разрешенные домены, проверяем их
        if (!empty($this->config['allowed_domains'])) {
            foreach ($this->config['allowed_domains'] as $domain) {
                if (str_contains($host, $domain)) {
                    return true;
                }
            }
            return false;
        }
        
        return true;
    }
    
    private function resolveUrl(string $href, \DOMDocument $dom): string
    {
        // Если URL уже абсолютный, возвращаем как есть
        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }
        
        // Для относительных URL нужно базовый URL, здесь упрощенная версия
        return $href;
    }

    public function addPosts(string $url): array
    {
        $this->validateUrl($url);
        
        try {
            $html = $this->fetchHtmlContent($url);
            $links = $this->extractLinksWithCrawler($html);
            
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
                'links' => array_map(fn($l) => $l['url'], $processedLinks),
            ]);
            
            // Отправляем задачи в очередь
            $this->dispatchJobs($processedLinks);
            
            return $processedLinks;
            
        } catch (\Exception $e) {
            Log::error('Failed to add posts from URL', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    private function extractLinksWithCrawler(string $html): array
    {
        $crawler = new Crawler($html);

        try {
            $sectionHeadings = $this->config['section_headings'];

            if (!empty($sectionHeadings)) {
                return $this->extractLinksFromSections($crawler, $sectionHeadings);
            }

            $links = $crawler->filter($this->config['selector'])->each(function (Crawler $node) {
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
                'selector' => $this->config['selector'],
                'error' => $e->getMessage()
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
            if (!in_array($heading, $headings)) {
                return;
            }

            $td->filter('a[href]')->each(function (Crawler $node) use (&$links) {
                $text = trim($node->text());
                $url = trim($node->attr('href'));

                if (!empty($url) && !empty($text)) {
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
            if (!in_array($url, $seenUrls)) {
                $uniqueLinks[] = $link;
                $seenUrls[] = $url;
            }
        }
        
        // Применяем смещение и ограничение
        return array_slice($uniqueLinks, $offset, $maxLinks);
    }
    
    private function getSelectorForUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        foreach (config('releases.domain_selectors', []) as $domain => $selector) {
            if (str_contains($host, $domain)) {
                return $selector;
            }
        }

        return config('releases.post_selector', 'article-body');
    }

    private function dispatchJobs(array $links): void
    {
        if (!$this->config['enable_job_dispatch']) {
            Log::info('Job dispatch is disabled', ['links_count' => count($links)]);
            return;
        }

        foreach ($links as $link) {
            try {
                StorePostJob::dispatch([
                    'url'      => $link['url'],
                    'selector' => $this->getSelectorForUrl($link['url']),
                    'tag_ids'  => [],
                    'translate' => null,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to dispatch job for link', [
                    'url' => $link['url'],
                    'error' => $e->getMessage()
                ]);
                // Продолжаем обработку других ссылок
            }
        }
    }
}

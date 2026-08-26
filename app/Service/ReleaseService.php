<?php

namespace App\Service;

use App\Jobs\StorePostJob;
use App\Models\Release;
use App\Support\ContentSelectorResolver;
use App\Support\HostMatcher;
use App\Support\SanitizedHref;
use App\Support\UrlSafetyChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\TransferStats;
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
    /**
     * Публичный, потому что тем же защищённым путём ходит импорт новостей
     * (NewsImportService): дайджест один и тот же, а заводить рядом пятую
     * копию SSRF-логики с IP-пиннингом незачем.
     */
    public function fetchHtmlContent(string $url): string
    {
        $client = new Client([
            'timeout' => $this->config['timeout'],
            'headers' => [
                'User-Agent' => $this->config['user_agent'],
            ],
            'allow_redirects' => false,
        ]);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->urlSafetyChecker->resolveTarget($url);
            if ($target === null) {
                throw new \RuntimeException("Blocked unsafe URL: {$url}");
            }

            $url = $target->url;
            $connectedIp = null;

            // IP закрепляется через CURLOPT_RESOLVE вместо повторного резолва
            // хоста Guzzle'ом — иначе между проверкой и запросом DNS-запись
            // могла бы смениться на приватный адрес (rebinding/TOCTOU).
            // CURLOPT_MAXFILESIZE_LARGE защищает от гигантского/бесконечного
            // ответа со страницы релиза (DoS воркера по памяти/диску).
            $response = $client->get($url, [
                'on_stats' => function (TransferStats $stats) use (&$connectedIp) {
                    $connectedIp = $stats->getHandlerStats()['primary_ip'] ?? null;
                },
                'curl' => array_filter([
                    CURLOPT_RESOLVE => $target->resolveEntry() !== null ? [$target->resolveEntry()] : null,
                    CURLOPT_MAXFILESIZE_LARGE => (int) config('releases.max_content_length'),
                ]),
            ]);

            // CURLOPT_RESOLVE молчит, если запись не смэтчилась с хостом
            // (расхождение нормализации между parse_url и libcurl) — тогда
            // curl резолвит сам, и пиннинг превращается в фикцию. Сверяем
            // фактический адрес соединения с проверенным.
            if ($connectedIp !== null && ! $target->ipMatches($connectedIp)) {
                throw new \RuntimeException(
                    "Connection went to unverified IP {$connectedIp} instead of pinned {$target->ip}: {$url}"
                );
            }

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

    public function addPosts(string $url): array
    {
        $this->validateUrl($url);

        try {
            $html = $this->fetchHtmlContent($url);
            $links = $this->extractLinksWithCrawler(
                $html,
                $this->getLinkSelectorForUrl($url),
                $this->getSponsorRuleForUrl($url)
            );

            if (empty($links)) {
                Log::debug('No links found on the page', ['url' => $url]);

                return [];
            }

            // Применяем конфигурацию для выбора ссылок
            $processedLinks = $this->processLinks($links);

            Log::debug('Processed links for dispatch', [
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

    private function extractLinksWithCrawler(string $html, ?string $domainSelector = null, ?array $sponsorRule = null): array
    {
        $crawler = new Crawler($html);
        $selector = $domainSelector ?? $this->config['selector'];

        try {
            // Адреса рекламных ссылок собираются ОДИН раз на весь документ, а
            // не проверяются у каждой найденной ссылки: подъём к предку
            // (Crawler::closest) заново компилирует CSS-селектор на каждом
            // уровне вёрстки, а вёрстка дайджеста табличная — на выпуск это
            // выходило под три сотни разборов селектора ради двух-трёх меток
            // во всём документе.
            $sponsoredHrefs = $this->sponsoredHrefs($crawler, $sponsorRule);

            // Секционная фильтрация по h2-заголовкам (Articles/Tutorials и
            // т.п.) заточена под td.bodyContent-вёрстку mailer.inovica.com.
            // Для доменов с явно настроенным своим селектором ссылок эта
            // логика не подходит и должна быть пропущена целиком
            $sectionHeadings = $this->config['section_headings'];

            if ($domainSelector === null && ! empty($sectionHeadings)) {
                return $this->extractLinksFromSections($crawler, $sectionHeadings, $sponsoredHrefs);
            }

            $links = $crawler->filter($selector)->each(function (Crawler $node) use ($sponsoredHrefs) {
                $text = trim($node->text());
                $url = $this->sanitizeHref($node->attr('href'));

                if (empty($url) || empty($text) || isset($sponsoredHrefs[$url])) {
                    return null;
                }

                return ['text' => $text, 'url' => $url];
            });

            $this->logSponsored($sponsoredHrefs);

            return array_filter($links);

        } catch (\Exception $e) {
            Log::error('Failed to extract links with crawler', [
                'selector' => $selector,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Отбраковывает href со схемой, которую нельзя пускать дальше.
     *
     * href берётся со страницы стороннего дайджеста, а дальше оседает в
     * posts.url и рендерится кликабельной ссылкой — и в панели
     * (PostResource), и на публичной странице поста. Скачать `javascript:…`
     * UrlSafetyChecker не даст, но пост-заглушку с этим url джоба всё равно
     * создаёт, и админ, открывший её разбираться с ошибкой, кликает по
     * ссылке уже в origin панели, где CSP намеренно ослаблена до
     * 'unsafe-inline' — то есть javascript:-URI там не блокируется.
     */
    private function sanitizeHref(?string $href): ?string
    {
        $safe = SanitizedHref::fromString($href);

        if ($safe === null && trim((string) $href) !== '') {
            Log::warning('ReleaseService: ссылка с недопустимой схемой отброшена');
        }

        return $safe;
    }

    /**
     * @param  array<string, true>  $sponsoredHrefs
     */
    private function extractLinksFromSections(Crawler $crawler, array $headings, array $sponsoredHrefs = []): array
    {
        $links = [];

        $crawler->filter('td.bodyContent')->each(function (Crawler $td) use (&$links, $headings, $sponsoredHrefs) {
            $h2 = $td->filter('h2');
            if ($h2->count() === 0) {
                return;
            }

            $heading = trim($h2->first()->text());
            if (! in_array($heading, $headings)) {
                return;
            }

            $td->filter('a[href]')->each(function (Crawler $node) use (&$links, $sponsoredHrefs) {
                $text = trim($node->text());
                $url = $this->sanitizeHref($node->attr('href'));

                // Отсев рекламы применяется в ОБЕИХ ветках извлечения. Иначе
                // правило, заведённое для дайджеста без своего селектора
                // ссылок (PHP Weekly и есть такой — он разбирается этой
                // веткой), выглядело бы настроенным и молча не работало.
                if (! empty($url) && ! empty($text) && ! isset($sponsoredHrefs[$url])) {
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
        $skipped = [];

        foreach ($links as $link) {
            $url = $link['url'];

            if (in_array($url, $seenUrls, true)) {
                continue;
            }

            $seenUrls[] = $url;

            // Отсев ДО среза по offset/max_links: иначе видео и репозитории
            // занимали бы слоты вместо статей.
            if ($this->isSkippedSource($url)) {
                $skipped[] = $url;

                continue;
            }

            $uniqueLinks[] = $link;
        }

        if ($skipped !== []) {
            Log::info('ReleaseService: ссылки пропущены как не-статьи', [
                'count' => count($skipped),
                'urls' => $skipped,
            ]);
        }

        // Применяем смещение и ограничение
        $selected = array_slice($uniqueLinks, $offset, $maxLinks);

        // Срез идёт ПОСЛЕ отсева не-статей, то есть режет уже только годные
        // ссылки. Пока выпуск давал 18 ссылок при потолке 20, срез не
        // срабатывал вовсе; с разбором заголовочных материалов JS Weekly
        // выпуск даёт 24, и четыре статьи молча исчезали бы из очереди.
        if (count($selected) < count($uniqueLinks)) {
            $dropped = array_merge(
                array_slice($uniqueLinks, 0, $offset),
                array_slice($uniqueLinks, $offset + $maxLinks)
            );

            Log::warning('ReleaseService: часть ссылок отброшена потолком max_links', [
                'max_links' => $maxLinks,
                'offset' => $offset,
                'suitable' => count($uniqueLinks),
                'dropped' => array_column($dropped, 'url'),
            ]);
        }

        return $selected;
    }

    /**
     * Ссылка ведёт на источник, который заведомо не является статьёй
     * (видео, репозиторий, справочник, страница подкаста).
     *
     * Отсеиваем до постановки джобы: парсер иначе честно скачивает такую
     * страницу, не находит контент по селектору и создаёт пост-заглушку,
     * которую потом разбирают руками. См. releases.skipped_domains.
     */
    private function isSkippedSource(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        foreach ((array) config('releases.skipped_domains', []) as $domain) {
            if (HostMatcher::matches($host, (string) $domain)) {
                return true;
            }
        }

        return false;
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
        return HostMatcher::lookup(
            (string) parse_url($url, PHP_URL_HOST),
            config('releases.parser_selectors_by_domain', [])
        );
    }

    /**
     * Правило «как отличить рекламный пункт выпуска от обычного» для домена
     * дайджеста: пара «контейнер пункта + метка внутри него».
     *
     * Возвращает null и для незнакомого домена, и для неполного правила: с
     * одним ключом из пары проверять нечего. Требуется именно строка —
     * `filled()` пропустил бы массив (частая опечатка при копипасте), а
     * DomCrawler на нём падает TypeError'ом, который не ловит ни один catch
     * по пути наверх.
     */
    private function getSponsorRuleForUrl(string $url): ?array
    {
        $rule = HostMatcher::lookup(
            (string) parse_url($url, PHP_URL_HOST),
            config('releases.parser_sponsor_markers_by_domain', [])
        );

        if ($rule === null) {
            return null;
        }

        if (! is_array($rule)) {
            Log::warning('ReleaseService: правило рекламных блоков не пара «контейнер + метка»');

            return null;
        }

        $item = $rule['item'] ?? null;
        $marker = $rule['marker'] ?? null;

        if (is_string($item) && $item !== '' && is_string($marker) && $marker !== '') {
            return ['item' => $item, 'marker' => $marker];
        }

        Log::warning('ReleaseService: правило рекламных блоков неполное, отсев выключен', [
            'rule' => $rule,
        ]);

        return null;
    }

    /**
     * Адреса всех ссылок, лежащих в рекламных пунктах выпуска.
     *
     * Спонсорский пункт свёрстан ровно как обычный материал: тот же
     * контейнер с тем же классом, та же ссылка-заголовок. Отличает его
     * только метка («sponsor») внутри пункта, поэтому идём от метки вверх к
     * пункту и забираем оттуда все ссылки — так весь подъём по вёрстке
     * делается по разу на метку, а не на каждую ссылку выпуска.
     *
     * Сравнение потом идёт по адресу: рекламный лендинг и материал выпуска
     * с одним и тем же href — это одна и та же ссылка, различить их нечем
     * (да и processLinks() всё равно схлопнул бы её в одну запись).
     *
     * @return array<string, true>
     */
    private function sponsoredHrefs(Crawler $crawler, ?array $sponsorRule): array
    {
        if ($sponsorRule === null) {
            return [];
        }

        $hrefs = [];

        try {
            $crawler->filter($sponsorRule['marker'])->each(function (Crawler $marker) use ($sponsorRule, &$hrefs) {
                $marker->closest($sponsorRule['item'])?->filter('a[href]')->each(function (Crawler $link) use (&$hrefs) {
                    $href = SanitizedHref::fromString($link->attr('href'));

                    if ($href !== null) {
                        $hrefs[$href] = true;
                    }
                });
            });
        } catch (\Throwable $e) {
            // Опечатка в селекторе правила приезжает сюда SyntaxErrorException
            // (а нестроковое значение — TypeError), и оба вылетали наружу,
            // обрывая разбор выпуска целиком. Цена ошибки несимметрична:
            // без правила в очередь попадёт реклама (её видно и можно
            // удалить), с исключением не разберётся ни одна статья.
            Log::warning('ReleaseService: правило рекламных блоков не применилось', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $hrefs;
    }

    private function logSponsored(array $sponsoredHrefs): void
    {
        if ($sponsoredHrefs === []) {
            return;
        }

        // С адресами, а не счётчиком: когда отсев однажды съест нормальную
        // статью (метка переехала в вёрстке), по числу «пропущено 4»
        // проверить это будет нечем. Формат тот же, что у пропущенных
        // не-статей в processLinks().
        Log::info('ReleaseService: рекламные ссылки выпуска исключены из разбора', [
            'count' => count($sponsoredHrefs),
            'urls' => array_keys($sponsoredHrefs),
        ]);
    }

    private function dispatchJobs(array $links): void
    {
        if (! $this->config['enable_job_dispatch']) {
            Log::debug('Job dispatch is disabled', ['links_count' => count($links)]);

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

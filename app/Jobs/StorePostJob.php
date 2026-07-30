<?php

namespace App\Jobs;

use App\DataTransferObjects\PostData;
use App\Models\Post;
use App\Service\ContentImageService;
use App\Service\DiagramTranslatorService;
use App\Service\PostService;
use App\Support\UrlSafetyChecker;
use App\Traits\TranslatesNodes;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stichoza\GoogleTranslate\GoogleTranslate;

/**
 * ShouldBeUnique — PostService::store() делает updateOrCreate() по url, но
 * это защита только от повторного handle() ОДНОЙ и той же джобы (retry).
 * Без distributed lock две параллельные джобы по одному и тому же url (два
 * воркера queue:work, либо повторный ParseReleaseJob до того как первая
 * успела закоммититься) всё ещё могут одновременно пройти проверку
 * "существует ли Post с таким url" и создать дубликат — updateOrCreate не
 * атомарен без UNIQUE-индекса в БД, а его пока нет (см.
 * docs/blog-improvements-plan.md — в данных уже есть дубли url).
 */
class StorePostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TranslatesNodes;

    private const MAX_REDIRECTS = 5;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * Держим лок на весь возможный жизненный цикл джобы с учётом retry —
     * timeout (300с) x tries (3) + максимальный backoff (900с), с запасом.
     */
    public int $uniqueFor = 3600;

    private $data;

    private PostService $service;

    private GoogleTranslate $googleTranslate;

    private ContentImageService $imageService;

    private UrlSafetyChecker $urlSafetyChecker;

    private DiagramTranslatorService $diagramTranslator;

    /**
     * Причина, по которой не удалось скачать страницу — заполняется внутри
     * fetchHtml(), чтобы handle() мог записать её в parse_error поста, а не
     * только в лог.
     */
    private ?string $fetchError = null;

    public function __construct($data)
    {
        //
        $this->data = $data;
    }

    /**
     * url — естественный ключ поста (см. PostService::store). Если его нет
     * (ручная загрузка через html_file), дедуплицируем по самому html_file —
     * иначе все такие джобы схлопнулись бы в один uniqueId и блокировали
     * друг друга.
     */
    public function uniqueId(): string
    {
        return md5($this->data['url'] ?: ($this->data['html_file'] ?? serialize($this->data)));
    }

    public function handle(PostService $service, ContentImageService $imageService, UrlSafetyChecker $urlSafetyChecker, DiagramTranslatorService $diagramTranslator): void
    {
        $this->service = $service;
        $this->imageService = $imageService;
        $this->urlSafetyChecker = $urlSafetyChecker;
        $this->diagramTranslator = $diagramTranslator;

        $titleTranslator = $this->makeGoogleTranslate();

        $failure = null;

        try {
            $dom = new DOMDocument;

            $html = $this->fetchHtml();

            Log::info('StorePostJob: response', [
                'length' => strlen((string) $html),
                'has_crayons_article' => str_contains((string) $html, 'crayons-article__body'),
            ]);

            if ($html === false) {
                $failure = $this->fetchError ?? 'Не удалось скачать страницу статьи';
            } elseif (empty($html)) {
                $failure = 'Источник вернул пустой ответ';
            } elseif ($this->isChallengePage($html)) {
                $failure = 'Вместо статьи отдана антибот-заглушка (Cloudflare)';
            } else {
                @$dom->loadHTML($html);

                $this->applyOgImage($dom);
                $this->applyPublishedDate($dom);

                $h1 = $this->resolveH1($dom);

                if ($h1->length > 0) {
                    $this->extractArticle($dom, $h1, $titleTranslator);
                }
            }
        } catch (\Throwable $exception) {
            Log::error('StorePostJob error: '.$exception->getMessage(), [
                'class' => get_class($exception),
                'line' => $exception->getLine(),
            ]);

            $failure = 'Ошибка при разборе страницы: '.$exception->getMessage();
        }

        if ($failure === null && empty($this->data['title'])) {
            $failure = 'На странице не найден заголовок статьи (<h1>/<title>)';
        }

        if ($failure === null && empty($this->data['content'])) {
            $failure = sprintf(
                'Не удалось извлечь контент по селектору «%s» — вероятно, у источника другая вёрстка',
                $this->data['selector'] ?? ''
            );
        }

        // Пост создаётся в любом случае: неразобранная ссылка должна быть
        // видна в админке (с причиной сбоя), а не теряться в laravel.log.
        // Публикации это не мешает — published по умолчанию false.
        $this->applyParseResult($failure);

        $this->data['translation_incomplete'] = $this->hasTranslationFallbacks();

        $this->service->store(PostData::fromArray($this->data));
    }

    /**
     * Записывает исход парсинга в данные поста: причину сбоя (или её
     * снятие, если на этот раз всё разобралось) и время попытки.
     */
    private function applyParseResult(?string $failure): void
    {
        $this->data['parsed_at'] = now()->toDateTimeString();

        if ($failure === null) {
            $this->data['parse_status'] = Post::PARSE_STATUS_OK;
            // Пустая строка, а не null: PostData::toArray() отбрасывает null,
            // и ошибка от прошлой неудачной попытки осталась бы на посте.
            $this->data['parse_error'] = '';

            return;
        }

        Log::warning('StorePostJob: parse failed', [
            'url' => $this->data['url'] ?? null,
            'reason' => $failure,
        ]);

        $this->data['parse_status'] = Post::PARSE_STATUS_FAILED;
        $this->data['parse_error'] = $failure;

        if (empty($this->data['title'])) {
            $this->data['title'] = $this->fallbackTitle();
        }
    }

    /**
     * Заголовок для поста, у которого не удалось разобрать страницу:
     * текст ссылки со страницы релиза, иначе — читаемый огрызок URL
     * (title в БД NOT NULL, а пустой заголовок в списке админки
     * невозможно опознать).
     */
    private function fallbackTitle(): string
    {
        if (! empty($this->data['link_text'])) {
            return Str::limit(trim($this->data['link_text']), 250, '');
        }

        $url = (string) ($this->data['url'] ?? '');

        if ($url === '') {
            return Str::limit('Не разобрано: '.basename((string) ($this->data['html_file'] ?? 'без источника')), 250, '');
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $slug = trim(str_replace(['-', '_', '/'], ' ', (string) parse_url($url, PHP_URL_PATH)));

        return Str::limit(trim($host.' '.$slug) ?: $url, 250, '');
    }

    /**
     * Скачивает HTML статьи: из локального файла (если передан html_file,
     * см. --html-file у post:parse), либо через curl/curl-impersonate
     * (curl-impersonate — если задан config('releases.curl_binary'),
     * чтобы обойти TLS-фингерпринтинг антибот-защит).
     *
     * URL статьи приходит из ссылок на странице релиза (чужого дайджеста),
     * поэтому автоследование редиректов (-L) отключено — каждый хоп
     * ревалидируется через UrlSafetyChecker, иначе 3xx со страницы-источника
     * может увести fetch на localhost/внутреннюю сеть/метаданные облака (SSRF).
     */
    private function fetchHtml(): string|false
    {
        if (! empty($this->data['html_file'])) {
            Log::info('StorePostJob: reading from file', ['file' => $this->data['html_file']]);

            $contents = @file_get_contents($this->data['html_file']);

            if ($contents === false) {
                $this->fetchError = 'Не удалось прочитать файл '.$this->data['html_file'];
            }

            return $contents;
        }

        $url = $this->data['url'];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->urlSafetyChecker->resolvePublicIp($url);
            if ($ip === null) {
                Log::warning('StorePostJob: unsafe URL blocked', ['url' => $url, 'original_url' => $this->data['url']]);
                $this->fetchError = "Ссылка заблокирована проверкой безопасности: {$url} (недоступный DNS либо приватный адрес)";

                return false;
            }

            $result = $this->curlFetchOnce($url, $ip);

            if ($result['location'] !== null) {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($result['location']));

                continue;
            }

            if ($result['status'] === null) {
                $this->fetchError = "Источник не ответил: {$url} (таймаут, обрыв соединения либо превышен лимит размера ответа)";

                return false;
            }

            if ($result['status'] >= 400) {
                $this->fetchError = "Источник вернул HTTP {$result['status']}: {$url}";

                return false;
            }

            return $result['body'];
        }

        Log::warning('StorePostJob: too many redirects', ['url' => $this->data['url']]);
        $this->fetchError = 'Слишком много редиректов (>'.self::MAX_REDIRECTS.')';

        return false;
    }

    /**
     * Выполняет один HTTP-запрос без автоследования редиректов: заголовки
     * ответа пишутся в отдельный временный файл (-D), тело — в другой
     * (--output), чтобы достать статус/Location без "-L".
     *
     * $resolvedIp закрепляется через --resolve вместо того, чтобы curl
     * резолвил хост самостоятельно — иначе между проверкой в
     * UrlSafetyChecker и самим запросом DNS-запись могла бы смениться на
     * приватный адрес (rebinding/TOCTOU).
     *
     * @return array{body: string|false, location: ?string, status: ?int}
     */
    private function curlFetchOnce(string $url, string $resolvedIp): array
    {
        $tmpBody = tempnam(sys_get_temp_dir(), 'curl_body_');
        $tmpHeaders = tempnam(sys_get_temp_dir(), 'curl_headers_');

        $escapedUrl = escapeshellarg($url);
        $resolve = '--resolve '.escapeshellarg($this->hostPort($url).':'.$resolvedIp).' ';
        $proxy = config('releases.curl_proxy') ? '--socks5 '.escapeshellarg(config('releases.curl_proxy')).' ' : '';
        $impersonate = config('releases.curl_binary');
        $proto = "--proto '=http,https' --proto-redir '=http,https' ";
        // Ни --max-time, ни сам факт allowed_domains не ограничивают ОБЪЁМ
        // ответа — страница может отдавать гигабайты/бесконечный поток и
        // положить воркер по памяти/диску.
        $maxFilesize = '--max-filesize '.((int) config('releases.max_content_length')).' ';

        if ($impersonate) {
            // curl-impersonate сам выставляет TLS-отпечаток и заголовки Chrome —
            // свои не добавляем, чтобы не выдать себя дублями заголовков
            $command = escapeshellcmd($impersonate).' -s --max-time 30 '.
                $proto.$resolve.$proxy.$maxFilesize.
                '-D '.escapeshellarg($tmpHeaders).' --output '.escapeshellarg($tmpBody).' '.
                "{$escapedUrl} 2>/dev/null";
        } else {
            $command = '/usr/bin/curl -s --max-time 30 --http2 '.
                $proto.$resolve.$proxy.$maxFilesize.
                "-H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36' ".
                "-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ".
                "-H 'Accept-Language: en-US,en;q=0.9' ".
                '-D '.escapeshellarg($tmpHeaders).' --output '.escapeshellarg($tmpBody).' '.
                "{$escapedUrl} 2>/dev/null";
        }

        shell_exec($command);

        $body = file_get_contents($tmpBody);
        $headers = (string) file_get_contents($tmpHeaders);
        unlink($tmpBody);
        unlink($tmpHeaders);

        return [
            'body' => $body,
            'location' => $this->extractRedirectLocation($headers),
            'status' => $this->extractStatus($headers),
        ];
    }

    /**
     * "host:port" в формате, который ожидает curl --resolve.
     */
    private function hostPort(string $url): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80);

        return $parts['host'].':'.$port;
    }

    /**
     * Достаёт Location из последнего HTTP-ответа в заголовках curl
     * (при проксировании/keep-alive заголовки могут содержать несколько
     * блоков "HTTP/..." — берём последний) для статусов 3xx.
     */
    private function extractRedirectLocation(string $headers): ?string
    {
        $status = $this->extractStatus($headers);

        if ($status === null || $status < 300 || $status >= 400) {
            return null;
        }

        if (! preg_match('/^Location:\s*(\S+)/mi', $this->lastHeaderBlock($headers), $locationMatch)) {
            return null;
        }

        return trim($locationMatch[1]);
    }

    /**
     * HTTP-статус последнего ответа. null означает, что заголовков нет
     * вообще — то есть запрос не состоялся (таймаут, обрыв, ошибка TLS),
     * и это отдельная от "4xx/5xx" причина сбоя в parse_error.
     */
    private function extractStatus(string $headers): ?int
    {
        if (! preg_match('/^HTTP\/\S+\s+(\d{3})/', $this->lastHeaderBlock($headers), $statusMatch)) {
            return null;
        }

        return (int) $statusMatch[1];
    }

    private function lastHeaderBlock(string $headers): string
    {
        $blocks = preg_split('/\r?\n\r?\n(?=HTTP\/)/', trim($headers));
        $lastBlock = end($blocks);

        return $lastBlock === false ? '' : $lastBlock;
    }

    /**
     * Извлекает OG-изображение статьи (meta og:image) и скачивает его,
     * если превью ещё не задано другим способом.
     */
    private function applyOgImage(DOMDocument $dom): void
    {
        if (! empty($this->data['preview_image'])) {
            return;
        }

        $finder = new DomXPath($dom);
        $ogImage = $finder->query("//meta[@property='og:image']/@content");
        if ($ogImage->length === 0) {
            return;
        }

        $ogImageUrl = $ogImage->item(0)->nodeValue;
        $imagePath = $this->imageService->downloadImage($ogImageUrl);
        if ($imagePath) {
            $this->data['preview_image'] = $imagePath;
            $this->data['main_image'] = $imagePath;
        }
    }

    /**
     * created_at поста должен отражать, когда статья вышла у источника,
     * а не когда её сюда затащил скрейпер (иначе дата на публичной
     * странице/RSS не соответствует реальной дате публикации оригинала).
     * Источник даты ищется в порядке убывания надёжности: структурированный
     * JSON-LD, article:published_time, itemprop/name meta, <time datetime>.
     */
    private function applyPublishedDate(DOMDocument $dom): void
    {
        $raw = $this->extractPublishedDateRaw($dom);
        if ($raw === null) {
            return;
        }

        try {
            $date = Carbon::parse($raw);
        } catch (\Throwable $e) {
            Log::warning('StorePostJob: failed to parse published date', ['raw' => $raw, 'error' => $e->getMessage()]);

            return;
        }

        // Защита от мусора в разметке источника (пустая/дефолтная дата,
        // будущее число) — в этом случае лучше оставить обычный now().
        if ($date->isFuture() || $date->year < 2000) {
            Log::warning('StorePostJob: implausible published date, ignoring', ['raw' => $raw]);

            return;
        }

        $this->data['created_at'] = $date->toDateTimeString();
    }

    private function extractPublishedDateRaw(DOMDocument $dom): ?string
    {
        $finder = new DomXPath($dom);

        return $this->findJsonLdDatePublished($dom)
            ?? $this->firstAttributeValue($finder, "//meta[@property='article:published_time']/@content")
            ?? $this->firstAttributeValue($finder, "//meta[@itemprop='datePublished']/@content")
            ?? $this->firstAttributeValue($finder, "//meta[@name='date']/@content")
            ?? $this->firstAttributeValue($finder, '//time[@datetime]/@datetime');
    }

    private function firstAttributeValue(DOMXPath $finder, string $query): ?string
    {
        $nodes = $finder->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim($nodes->item(0)->nodeValue);

        return $value !== '' ? $value : null;
    }

    /**
     * datePublished из JSON-LD (<script type="application/ld+json">) —
     * структурированные данные (Article/BlogPosting), которые сайты обычно
     * пишут для Google и которые надёжнее человекочитаемой разметки.
     * Формат — либо один объект, либо массив объектов, либо {"@graph": [...]}.
     */
    private function findJsonLdDatePublished(DOMDocument $dom): ?string
    {
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                continue;
            }

            $json = json_decode($script->nodeValue, true);
            if (! is_array($json)) {
                continue;
            }

            $nodes = array_is_list($json) ? $json : [$json];

            foreach ($nodes as $node) {
                $date = $this->datePublishedFromJsonLdNode($node);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function datePublishedFromJsonLdNode(mixed $node): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        if (isset($node['datePublished']) && is_string($node['datePublished'])) {
            return $node['datePublished'];
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $graphNode) {
                $date = $this->datePublishedFromJsonLdNode($graphNode);
                if ($date !== null) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * Возвращает список <h1> статьи. Если на странице их нет, строит
     * заглушку из <title> (обрезая суффикс вида " | Site Name"), чтобы
     * дальнейшая экстракция заголовка не осталась совсем без кандидата.
     */
    private function resolveH1(DOMDocument $dom): DOMNodeList
    {
        $h1 = $dom->getElementsByTagName('h1');
        if ($h1->length > 0) {
            return $h1;
        }

        $titleTag = $dom->getElementsByTagName('title');
        if ($titleTag->length === 0) {
            return $h1;
        }

        $rawTitle = $titleTag->item(0)->nodeValue;
        // Strip site name suffix (e.g. "Article Title | Site Name")
        $rawTitle = preg_replace('/\s*[|\-—]\s*[^|\-—]+$/', '', $rawTitle);
        $fakeH1 = $dom->createElement('h1', htmlspecialchars(trim($rawTitle)));
        $dom->documentElement->appendChild($fakeH1);

        return $dom->getElementsByTagName('h1');
    }

    /**
     * Заполняет заголовок и контент поста из DOM статьи: выбирает
     * заголовок среди кандидатов <h1>, переводит обложку, чистит
     * интерфейсный мусор и переводит содержимое по CSS/ID-селектору.
     */
    private function extractArticle(DOMDocument $dom, DOMNodeList $h1, GoogleTranslate $titleTranslator): void
    {
        $title = $this->selectArticleTitleNode($h1)->nodeValue;
        $this->data['title'] = $titleTranslator->translate($title);

        $this->translateCoverImage();

        // Категория определяется позже, в PostService::store()/update() —
        // там уже доступен полный текст статьи (см. CategoryDetectorService),
        // а на этом этапе контент ещё не извлечён из DOM
        Log::info('StorePostJob: extracting content', ['title' => $this->data['title'], 'category_id' => $this->data['category_id'] ?? null]);

        $finder = new DomXPath($dom);

        $this->stripKnownJunk($finder);

        $selector = $this->data['selector'];
        $xpathQuery = $this->buildSelectorXPath($selector);
        $nodes = $finder->query($xpathQuery);

        Log::info('selector nodes found', ['selector' => $selector, 'xpath' => $xpathQuery, 'count' => $nodes->count()]);

        $this->translateContentNodes($dom, $nodes);
    }

    /**
     * Переводит текст, распознанный OCR прямо на уже скачанной обложке
     * статьи (если она есть) — не зависит от найденного заголовка/
     * расположения фона, в отличие от прежнего ImageTranslatorService.
     */
    private function translateCoverImage(): void
    {
        if (empty($this->data['preview_image'])) {
            return;
        }

        try {
            $fullPath = Storage::disk('public')->path($this->data['preview_image']);
            $this->diagramTranslator->translate($fullPath);
        } catch (\Throwable $e) {
            Log::warning('DiagramTranslatorService: cover image translate failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Убирает панель действий Dev.to и известный интерфейсный мусор
     * (см. stripAccessibilityJunk/stripMediumSubscribeWidget/stripDuplicateTitleAndHero)
     * перед выбором контентных узлов по селектору.
     */
    private function stripKnownJunk(DOMXPath $finder): void
    {
        $panels = $finder->query("//div[contains(@class, 'highlight__panel') and contains(@class, 'js-actions-panel')]");

        foreach ($panels as $panel) {
            $panel->parentNode->removeChild($panel);
        }

        $this->stripAccessibilityJunk($finder);
        $this->stripMediumSubscribeWidget($finder);
        $this->stripJetBrainsChrome($finder);
        $this->stripDuplicateTitleAndHero($finder);
    }

    /**
     * Убирает обвязку блога JetBrains из тела статьи. В div.js-toc-content
     * (единственный контейнер, который вообще оборачивает текст статьи)
     * лежит не только сам текст: сверху — ссылки на категории и шапка автора
     * (аватар, имя, дата), снизу — список тегов с кнопками шаринга,
     * пагинация «Prev/Next post» и форма подписки на рассылку. Всё это
     * интерфейс, а не контент, и в переводе выглядит мусором.
     */
    private function stripJetBrainsChrome(DOMXPath $finder): void
    {
        $junkClasses = [
            'author-post',        // аватар + имя автора + дата публикации
            'content__row',       // теги статьи + кнопки «Поделиться»
            'content__pagination', // ссылки на предыдущий/следующий пост
            'content__form',      // форма подписки на рассылку
        ];

        foreach ($junkClasses as $class) {
            $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");

            foreach (iterator_to_array($nodes) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Ссылки на разделы блога (<a class="tag">Community</a>) идут прямо
        // перед заголовком статьи, вне какой-либо обёртки — удаляем точечно.
        $tags = $finder->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' tag ') and contains(@href, '/category/')]");

        foreach (iterator_to_array($tags) as $tag) {
            $tag->parentNode?->removeChild($tag);
        }
    }

    /**
     * Переводит CSS/ID-подобный селектор (#id, .class, tag, или голое
     * имя класса без точки) в XPath-запрос для поиска контентных узлов.
     * selector задаётся вручную в админке при добавлении поста/релиза —
     * значение экранируется через xpathLiteral(), а не подставляется в
     * запрос напрямую, иначе '] | //script | //*[@id=' сломало бы структуру
     * XPath-выражения (XPath injection).
     */
    private function buildSelectorXPath(string $selector): string
    {
        if (str_starts_with($selector, '#')) {
            return '//*[@id='.$this->xpathLiteral(ltrim($selector, '#')).']';
        }

        if (str_starts_with($selector, '.')) {
            $class = ltrim($selector, '.');

            return "//*[contains(concat(' ', normalize-space(@class), ' '), concat(' ', ".$this->xpathLiteral($class).", ' '))]";
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $selector)) {
            return "(//{$selector})[1]";
        }

        return "//*[contains(concat(' ', normalize-space(@class), ' '), concat(' ', ".$this->xpathLiteral($selector).", ' '))]";
    }

    /**
     * Строит безопасный XPath string-литерал из произвольной строки. XPath
     * 1.0 не умеет экранировать кавычку внутри строки того же типа — если
     * значение содержит и одинарные, и двойные кавычки, единственный
     * стандартный способ — склеить через concat().
     */
    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        if (! str_contains($value, '"')) {
            return "\"{$value}\"";
        }

        $parts = array_map(fn ($part) => "'{$part}'", explode("'", $value));

        return 'concat('.implode(', "\'", ', $parts).')';
    }

    /**
     * Сохраняет оригинальный HTML найденных узлов, переводит их
     * (TranslatesNodes::processNode) и записывает итоговый контент
     * в $this->data, с фолбэком превью-картинки из первой локальной
     * картинки контента, если своей ещё нет.
     */
    private function translateContentNodes(DOMDocument $dom, DOMNodeList $nodes): void
    {
        $contentOrig = '';
        foreach ($nodes as $node) {
            $contentOrig .= $dom->saveHTML($node);
        }

        $this->googleTranslate = $this->makeGoogleTranslate();

        foreach ($nodes as $node) {
            $this->processNode($node);
        }

        $postContent = '';
        foreach ($nodes as $node) {
            $postContent .= $dom->saveHTML($node);
        }

        $postContent = $this->modifyContent($postContent);

        $postContent = $this->imageService->replacePictureElements($postContent);
        $postContent = $this->imageService->downloadAndReplaceImages($postContent);

        if (empty($postContent)) {
            return;
        }

        $this->data['content'] = $postContent;
        $this->data['content_orig'] = $contentOrig;

        if (empty($this->data['preview_image'])) {
            $imagePath = $this->extractFirstImagePath($postContent);
            if ($imagePath) {
                $this->data['preview_image'] = $imagePath;
                $this->data['main_image'] = $imagePath;
            }
        }
    }

    /**
     * Убирает элементы с классом "speechify-ignore" — общий маркер
     * инструментов доступности ("это не текст статьи, не читать вслух"),
     * используется не только на Medium. Захватывает шапку автора
     * (аватар/имя/время чтения/дата/кнопки Слушать-Поделиться) и подсказки
     * вида "нажмите Enter, чтобы открыть картинку в полный размер" —
     * мусор интерфейса, не контент статьи.
     */
    private function stripAccessibilityJunk(DOMXPath $finder): void
    {
        $junk = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' speechify-ignore ')]");

        foreach (iterator_to_array($junk) as $junkNode) {
            $junkNode->parentNode?->removeChild($junkNode);
        }
    }

    /**
     * Убирает встроенный виджет Medium «Get stories from X in your inbox»
     * (форма email, кнопки подписки, чекбокс «запомнить меня», recaptcha).
     * Medium вставляет его прямо посреди текста статьи (не в конце), из-за
     * чего в переводе появляется дублирующийся текст вида «Подписаться
     * Подписаться» и обрывок формы входа. Классы у Medium хэшированные и
     * меняются от сборки к сборке, поэтому ищем по стабильным маркерам —
     * полю email и блоку recaptcha — и удаляем их ближайшего общего предка.
     */
    private function stripMediumSubscribeWidget(DOMXPath $finder): void
    {
        $emailInputs = $finder->query("//input[@placeholder='Enter your email']");

        foreach (iterator_to_array($emailInputs) as $input) {
            $ancestor = $input->parentNode;

            while ($ancestor && $finder->query(".//*[@id='g-recaptcha']", $ancestor)->length === 0) {
                $ancestor = $ancestor->parentNode;
            }

            if ($ancestor instanceof DOMElement && $ancestor->parentNode) {
                $ancestor->parentNode->removeChild($ancestor);
            }
        }
    }

    /**
     * Убирает дублирующийся заголовок и обложку статьи из тела контента.
     * Страница поста уже рисует $post->title и $post->main_image своим
     * собственным <h1> и картинкой в шапке — если экстрактор (например,
     * <article> целиком на Medium/Stackademic) захватывает ещё и заголовок
     * статьи (<h1 data-testid="storyTitle">) с её обложкой (<figure><img>
     * сразу после заголовка), они дублируются на странице поста.
     */
    private function stripDuplicateTitleAndHero(DOMXPath $finder): void
    {
        foreach (iterator_to_array($finder->query("//h1[@data-testid='storyTitle']")) as $titleNode) {
            $figure = $finder->query('following::figure[1]', $titleNode)->item(0);
            if ($figure instanceof DOMElement && $figure->parentNode) {
                $figure->parentNode->removeChild($figure);
            }
        }

        // Любой оставшийся <h1> внутри тела статьи всегда дублирует
        // заголовок страницы — подзаголовки в контенте должны быть h2+.
        foreach (iterator_to_array($finder->query('//h1')) as $h1) {
            $h1->parentNode?->removeChild($h1);
        }
    }

    /**
     * Определяет антибот-заглушку (Cloudflare и т.п.) вместо реальной статьи.
     * Без этой проверки заглушка «Just a moment...» проходила как валидный
     * title и создавался мусорный пост с пустым контентом.
     */
    private function isChallengePage(string $html): bool
    {
        // Внимание: маркер '/cdn-cgi/challenge-platform/' НЕ подходит —
        // Cloudflare инжектит этот скрипт и в легитимные страницы (скоринг),
        // получается ложное срабатывание на нормальных статьях
        $markers = [
            '<title>Just a moment...</title>',
            'cf-browser-verification',
            'Attention Required! | Cloudflare',
        ];

        foreach ($markers as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Выбирает h1, соответствующий заголовку статьи, а не логотипу сайта.
     *
     * Частый паттерн вёрстки: <h1><a href="/">SiteName</a></h1> в шапке
     * идёт раньше настоящего h1 статьи в DOM-порядке — брать item(0) без
     * разбора значит получить название сайта вместо заголовка (см. баг
     * с "шитье.io" на stitcher.io — Google дословно перевёл имя сайта).
     */
    private function selectArticleTitleNode(DOMNodeList $h1Nodes): DOMElement
    {
        foreach ($h1Nodes as $node) {
            if (! $this->looksLikeSiteLogoHeading($node)) {
                return $node;
            }
        }

        return $h1Nodes->item(0);
    }

    private function looksLikeSiteLogoHeading(DOMElement $h1): bool
    {
        $links = $h1->getElementsByTagName('a');
        if ($links->length !== 1) {
            return false;
        }

        $href = trim($links->item(0)->getAttribute('href'));
        if (! in_array($href, ['/', '', '#'], true)) {
            return false;
        }

        return trim($h1->textContent) === trim($links->item(0)->textContent);
    }

    /**
     * Извлекает путь первой локально сохранённой картинки из контента.
     * Возвращает путь относительно storage/public (например images/content/xxx.jpg).
     */
    private function extractFirstImagePath(string $content): ?string
    {
        if (! preg_match('/<img[^>]+src="([^"]+)"/i', $content, $matches)) {
            return null;
        }

        $url = $matches[1];
        $storageUrl = rtrim(Storage::disk('public')->url(''), '/').'/';

        if (! str_starts_with($url, $storageUrl)) {
            return null;
        }

        return str_replace($storageUrl, '', $url);
    }

    /**
     * Modifies the content of a post to make it suitable for display.
     *
     * Replaces certain tags with versions that have a non-breaking space added to the start and/or end.
     * This is needed because some tags (like <code>) don't wrap properly without the extra space.
     *
     * @param  string  $postContent  The content of the post to modify.
     * @return string The modified content.
     */
    private function modifyContent(string $postContent): string
    {
        return str_replace(
            // The tags we want to modify
            ['<code>', '</code>', '<strong>'],
            // The modified versions of the tags
            ['&nbsp;<code>', '</code>&nbsp;', '&nbsp;<strong>'],
            // The content to modify
            $postContent
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('StorePostJob permanently failed', [
            'url' => $this->data['url'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}

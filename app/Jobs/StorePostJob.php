<?php

namespace App\Jobs;

use App\Service\ContentImageService;
use App\Service\ImageTranslatorService;
use App\Service\PostService;
use App\Traits\TranslatesNodes;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Stichoza\GoogleTranslate\GoogleTranslate;

class StorePostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TranslatesNodes;

    public int $timeout = 300;

    private $data;

    private PostService $service;

    private GoogleTranslate $googleTranslate;

    private ContentImageService $imageService;

    public function __construct($data)
    {
        //
        $this->data = $data;
        $this->service = new PostService;
        $this->imageService = new ContentImageService;
    }

    public function handle(): void
    {
        $googleTranslate = $this->makeGoogleTranslate();
        try {
            $dom = new DOMDocument;
            Log::info('job:url', [$this->data['url']]);
            if (! empty($this->data['html_file'])) {
                $html = file_get_contents($this->data['html_file']);
                Log::info('StorePostJob: reading from file', ['file' => $this->data['html_file']]);
            } else {
                $tmp = tempnam(sys_get_temp_dir(), 'curl_');
                $url = escapeshellarg($this->data['url']);
                $proxy = config('releases.curl_proxy') ? '--socks5 '.escapeshellarg(config('releases.curl_proxy')).' ' : '';
                $impersonate = config('releases.curl_binary');

                if ($impersonate) {
                    // curl-impersonate сам выставляет TLS-отпечаток и заголовки Chrome —
                    // свои не добавляем, чтобы не выдать себя дублями заголовков
                    $command = escapeshellcmd($impersonate).' -s -L --max-time 30 '.
                        $proxy.
                        '--output '.escapeshellarg($tmp).' '.
                        "{$url} 2>/dev/null";
                } else {
                    $command = '/usr/bin/curl -s -L --max-time 30 --http2 '.
                        $proxy.
                        "-H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36' ".
                        "-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ".
                        "-H 'Accept-Language: en-US,en;q=0.9' ".
                        '--output '.escapeshellarg($tmp).' '.
                        "{$url} 2>/dev/null";
                }

                shell_exec($command);
                $html = file_get_contents($tmp);
                unlink($tmp);
            }

            Log::info('StorePostJob: response', [
                'length' => strlen((string) $html),
                'has_crayons_article' => str_contains((string) $html, 'crayons-article__body'),
            ]);

            if (empty($html)) {
                Log::warning('StorePostJob: empty response', ['url' => $this->data['url']]);

                return;
            }

            if ($this->isChallengePage($html)) {
                Log::warning('StorePostJob: anti-bot challenge page, skipping', ['url' => $this->data['url']]);

                return;
            }

            @$dom->loadHTML($html);

            // Извлекаем OG-изображение статьи
            if (empty($this->data['preview_image'])) {
                $finder = new DomXPath($dom);
                $ogImage = $finder->query("//meta[@property='og:image']/@content");
                if ($ogImage->length > 0) {
                    $ogImageUrl = $ogImage->item(0)->nodeValue;
                    $imagePath = $this->imageService->downloadImage($ogImageUrl);
                    if ($imagePath) {
                        $this->data['preview_image'] = $imagePath;
                        $this->data['main_image'] = $imagePath;
                    }
                }
            }

            $h1 = $dom->getElementsByTagName('h1');
            if ($h1->length === 0) {
                $titleTag = $dom->getElementsByTagName('title');
                if ($titleTag->length > 0) {
                    $rawTitle = $titleTag->item(0)->nodeValue;
                    // Strip site name suffix (e.g. "Article Title | Site Name")
                    $rawTitle = preg_replace('/\s*[|\-—]\s*[^|\-—]+$/', '', $rawTitle);
                    $fakeH1 = $dom->createElement('h1', htmlspecialchars(trim($rawTitle)));
                    $dom->documentElement->appendChild($fakeH1);
                    $h1 = $dom->getElementsByTagName('h1');
                }
            }
            if ($h1->length > 0) {
                $title = $this->selectArticleTitleNode($h1)->nodeValue;
                $this->data['title'] = $googleTranslate->translate($title);

                // Переводим текст на обложке, если картинка уже скачана
                if (! empty($this->data['preview_image']) && ! empty($this->data['title'])) {
                    try {
                        $fullPath = Storage::disk('public')->path($this->data['preview_image']);
                        (new ImageTranslatorService)->translateCoverImage($fullPath, $this->data['title']);
                    } catch (\Throwable $e) {
                        Log::warning('ImageTranslatorService: failed', ['error' => $e->getMessage()]);
                    }
                }

                // Категория определяется позже, в PostService::store()/update() —
                // там уже доступен полный текст статьи (см. CategoryDetectorService),
                // а на этом этапе контент ещё не извлечён из DOM

                Log::info('title', [$this->data['title'], 'category_id' => $this->data['category_id'] ?? null]);

                $finder = new DomXPath($dom);

                $panels = $finder->query("//div[contains(@class, 'highlight__panel') and contains(@class, 'js-actions-panel')]");

                foreach ($panels as $panel) {
                    $panel->parentNode->removeChild($panel);
                }

                $this->stripAccessibilityJunk($finder);
                $this->stripMediumSubscribeWidget($finder);
                $this->stripDuplicateTitleAndHero($finder);

                $selector = $this->data['selector'];
                if (str_starts_with($selector, '#')) {
                    $xpathQuery = "//*[@id='".ltrim($selector, '#')."']";
                } elseif (str_starts_with($selector, '.')) {
                    $class = ltrim($selector, '.');
                    $xpathQuery = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
                } elseif (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $selector)) {
                    $xpathQuery = "(//{$selector})[1]";
                } else {
                    $xpathQuery = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$selector} ')]";
                }
                $nodes = $finder->query($xpathQuery);

                Log::info('selector nodes found', ['selector' => $selector, 'xpath' => $xpathQuery, 'count' => $nodes->count()]);

                // Сохраняем оригинальный контент до перевода
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

                if (! empty($postContent)) {
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

            }
        } catch (\Throwable $exception) {
            Log::error('StorePostJob error: '.$exception->getMessage(), [
                'class' => get_class($exception),
                'line' => $exception->getLine(),
            ]);
        }

        if (empty($this->data['title'])) {
            Log::warning('StorePostJob: skipping, no title found', ['url' => $this->data['url']]);

            return;
        }

        if (empty($this->data['content'])) {
            Log::warning('StorePostJob: skipping, no content extracted', [
                'url' => $this->data['url'],
                'selector' => $this->data['selector'] ?? null,
            ]);

            return;
        }

        $this->data['translation_incomplete'] = $this->hasTranslationFallbacks();

        $this->service->store($this->data);
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
}

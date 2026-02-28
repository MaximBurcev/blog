<?php

namespace App\Jobs;

use App\Service\CategoryDetectorService;
use App\Service\ContentImageService;
use App\Service\ImageTranslatorService;
use App\Service\PostService;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stichoza\GoogleTranslate\GoogleTranslate;

class StorePostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $data;
    private PostService $service;

    private GoogleTranslate $googleTranslate;
    private ContentImageService $imageService;


    public function __construct($data)
    {
        //
        $this->data = $data;
        $this->service = new PostService();
        $this->imageService = new ContentImageService();
    }


    public function handle(): void
    {
        $googleTranslate = new GoogleTranslate('ru');
        try {
            $dom = new DOMDocument();
            Log::info('job:url', [$this->data['url']]);
            //dd(file_get_contents($this->data['url']));
            if (!empty($this->data['html_file'])) {
                $html = file_get_contents($this->data['html_file']);
                Log::info('StorePostJob: reading from file', ['file' => $this->data['html_file']]);
            } else {
                $tmp = tempnam(sys_get_temp_dir(), 'curl_');
                $url = escapeshellarg($this->data['url']);
                shell_exec(
                    "/usr/bin/curl -s -L --max-time 30 --http2 " .
                    "-H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36' " .
                    "-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' " .
                    "-H 'Accept-Language: en-US,en;q=0.9' " .
                    "--output " . escapeshellarg($tmp) . " " .
                    "{$url} 2>/dev/null"
                );
                $html = file_get_contents($tmp);
                unlink($tmp);
            }

            Log::info('StorePostJob: response', [
                'length'                 => strlen((string) $html),
                'has_crayons_article'    => str_contains((string) $html, 'crayons-article__body'),
            ]);

            if (empty($html)) {
                Log::warning('StorePostJob: empty response', ['url' => $this->data['url']]);
                return;
            }

            @$dom->loadHTML($html);

            // Извлекаем OG-изображение статьи
            if (empty($this->data['preview_image'])) {
                $finder  = new DomXPath($dom);
                $ogImage = $finder->query("//meta[@property='og:image']/@content");
                if ($ogImage->length > 0) {
                    $ogImageUrl = $ogImage->item(0)->nodeValue;
                    $imagePath  = $this->imageService->downloadImage($ogImageUrl);
                    if ($imagePath) {
                        $this->data['preview_image'] = $imagePath;
                        $this->data['main_image']    = $imagePath;
                    }
                }
            }

            $h1 = $dom->getElementsByTagName("h1");
            if ($h1->length > 0) {
                $title = $h1->item(0)->nodeValue;
                $this->data['title'] = $googleTranslate->translate($title);

                // Переводим текст на обложке, если картинка уже скачана
                if (!empty($this->data['preview_image']) && !empty($this->data['title'])) {
                    $fullPath = Storage::disk('public')->path($this->data['preview_image']);
                    (new ImageTranslatorService())->translateCoverImage($fullPath, $this->data['title']);
                }

                if (empty($this->data['category_id'])) {
                    $this->data['category_id'] = (new CategoryDetectorService())->detect($title, $this->data['url']);
                }

                Log::info('title', [$this->data['title'], 'category_id' => $this->data['category_id'] ?? null]);

                $finder = new DomXPath($dom);

                $panels = $finder->query("//div[contains(@class, 'highlight__panel') and contains(@class, 'js-actions-panel')]");

                foreach ($panels as $panel) {
                    $panel->parentNode->removeChild($panel);
                }

                $selector = $this->data['selector'];
                if (str_starts_with($selector, '#')) {
                    $xpathQuery = "//*[@id='" . ltrim($selector, '#') . "']";
                } elseif (str_starts_with($selector, '.')) {
                    $class = ltrim($selector, '.');
                    $xpathQuery = "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
                } elseif (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $selector)) {
                    $xpathQuery = "//{$selector}";
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

                $this->googleTranslate = new GoogleTranslate('ru');

                foreach ($nodes as $node) {
                    $this->processNode($node);
                }

                $postContent = '';
                foreach ($nodes as $node) {
                    $postContent .= $dom->saveHTML($node);
                }


                $postContent = $this->modifyContent($postContent);

                $postContent = $this->imageService->downloadAndReplaceImages($postContent);

                if (!empty($postContent)) {
                    $this->data['content']      = $postContent;
                    $this->data['content_orig'] = $contentOrig;

                    if (empty($this->data['preview_image'])) {
                        $imagePath = $this->extractFirstImagePath($postContent);
                        if ($imagePath) {
                            $this->data['preview_image'] = $imagePath;
                            $this->data['main_image']    = $imagePath;
                        }
                    }
                }

            }
        } catch (\Throwable $exception) {
            Log::error('StorePostJob error: ' . $exception->getMessage(), [
                'class' => get_class($exception),
                'line'  => $exception->getLine(),
            ]);
        }

        if (empty($this->data['title'])) {
            Log::warning('StorePostJob: skipping, no title found', ['url' => $this->data['url']]);
            return;
        }

        $this->service->store($this->data);
    }

    private function showDOMNode(\DOMNode $domNode, $googleTranslate): void
    {

        foreach ($domNode->childNodes as $node) {
            Log::info('nodeName', [$node->nodeName]);

            echo $this->formatNode($googleTranslate->translate($node->nodeValue), $node->nodeName);

            if ($node->hasChildNodes()) {
                $this->showDOMNode($node, $googleTranslate);
            }
        }

    }

    private function formatNode($value, $nodeName): string
    {
        return '<' . $nodeName . '>' . $value . '</' . $nodeName . '>';
    }

    /**
     * Replaces text in DOM nodes recursively using a callback.
     *
     * @param \DOMNode $node The DOM node to process.
     * @param callable $callback A callback to replace the text in the node.
     */
    private function replaceTextInNodes(DOMNode $node, callable $callback): void
    {
        // If the node is a text node, replace its value with the callback result
        if ($node->nodeType === XML_TEXT_NODE) {
            Log::info('nodeValue', [$node->nodeValue]);
            $node->nodeValue = $callback($node->nodeValue);
        }

        // If the node has children, recursively process them
        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $childNode) {
                $this->replaceTextInNodes($childNode, $callback);
            }
        }
    }

    private function processNode(DOMNode $node, DOMNode|bool $parentNode = false): void
    {

        if ($node->nodeName == 'code') {
            return;
        }


        if ($node->nodeType === XML_TEXT_NODE) {
            $translatedText = $this->googleTranslate->translate($node->nodeValue);
            $node->nodeValue = $translatedText;
        }


        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $childNode) {
                $this->processNode($childNode, $node);
            }
        }
    }

    /**
     * Извлекает путь первой локально сохранённой картинки из контента.
     * Возвращает путь относительно storage/public (например images/content/xxx.jpg).
     */
    private function extractFirstImagePath(string $content): ?string
    {
        if (!preg_match('/<img[^>]+src="([^"]+)"/i', $content, $matches)) {
            return null;
        }

        $url        = $matches[1];
        $storageUrl = rtrim(Storage::disk('public')->url(''), '/') . '/';

        if (!str_starts_with($url, $storageUrl)) {
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
     * @param string $postContent The content of the post to modify.
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

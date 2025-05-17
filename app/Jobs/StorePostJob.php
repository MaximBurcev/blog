<?php

namespace App\Jobs;

use App\Service\ContentImageService;
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
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
                ]
            ]);


//            $response = Http::withHeaders([
//                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
//                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
//            ])->timeout(60)->get($this->data['url']);
//            $content = $response->body();

//            $proxy = 'socks5://103.137.249.210:12140';
//            $response = Http::withOptions([
//                'proxy'   => $proxy,
//                'timeout' => 60,
//            ])->withHeaders([
//                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
//            ])->get($this->data['url']);
//            $content = $response->body();


            @$dom->loadHTML(@file_get_contents($this->data['url'], false, $context));

            //$dom->loadHTML($content);

            $h1 = $dom->getElementsByTagName("h1");
            if ($h1->length > 0) {
                $title = $h1->item(0)->nodeValue;
                $this->data['title'] = $googleTranslate->translate($title);

                Log::info('title', [$this->data['title']]);
                Log::info('code', [$this->data['code']]);

                $finder = new DomXPath($dom);

                $panels = $finder->query("//div[contains(@class, 'highlight__panel') and contains(@class, 'js-actions-panel')]");

                foreach ($panels as $panel) {
                    $panel->parentNode->removeChild($panel);
                }

                $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $this->data['selector'] . " ')]");

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
                    $this->data['content'] = $postContent;
                }

            }
        } catch (\Exception $exception) {
            logger($exception->getMessage());
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

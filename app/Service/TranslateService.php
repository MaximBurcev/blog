<?php

namespace App\Service;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

class TranslateService
{


    private mixed $data;
    private ContentImageService $imageService;
    private GoogleTranslate $googleTranslate;

    public function __construct($data)
    {
        $this->data = $data;
        $this->imageService = new ContentImageService();
        $this->googleTranslate = new GoogleTranslate('ru');
    }

    public function translate(): mixed
    {
        $googleTranslate = new GoogleTranslate('ru');
        try {
            $dom = new DOMDocument();

            @$dom->loadHTML($this->data['content']);

            //$h1 = $dom->getElementsByTagName("h1");

            $this->data['title'] = $googleTranslate->translate($this->data['title']);

//            if ($h1->length > 0) {
//                $title = $h1->item(0)->nodeValue;
//
//                Log::info('title', [$this->data['title']]);
//                Log::info('code', [$this->data['code']]);
//            }



                $finder = new DomXPath($dom);

                $panels = $finder->query("//div[contains(@class, 'highlight__panel') and contains(@class, 'js-actions-panel')]");

                foreach ($panels as $panel) {
                    $panel->parentNode->removeChild($panel);
                }

                if ($this->data['selector'] && !empty($this->data['selector'])) {
                    $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $this->data['selector'] . " ')]");
                } else {
                    $nodes = $dom->getRootNode()->childNodes;
                }

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

                Log::info($this->data);


        } catch (\Exception $exception) {
            dd($exception->getMessage());
            //logger($exception->getMessage());
        }

        return $this->data;
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

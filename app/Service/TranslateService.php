<?php

namespace App\Service;

use App\Traits\TranslatesNodes;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

class TranslateService
{
    use TranslatesNodes;


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
        try {
            $dom = new DOMDocument();

            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $this->data['content']);

            $this->data['title'] = $this->googleTranslate->translate($this->data['title']);

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
            Log::error('TranslateService::translate failed', ['error' => $exception->getMessage()]);
        }

        return $this->data;
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

<?php

namespace App\Service;

use App\Support\SelectorXPathBuilder;
use App\Traits\TranslatesNodes;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

class TranslateService
{
    use TranslatesNodes;

    private mixed $data;

    private ContentImageService $imageService;

    private GoogleTranslate $googleTranslate;

    public function __construct(ContentImageService $imageService)
    {
        $this->imageService = $imageService;
        $this->googleTranslate = $this->makeGoogleTranslate();
    }

    public function translate(mixed $data): mixed
    {
        $this->data = $data;
        $this->translationFallbacks = 0;

        try {
            $dom = new DOMDocument;

            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$this->data['content']);

            $this->data['title'] = $this->googleTranslate->translate($this->data['title']);

            $finder = new DomXPath($dom);

            $panels = $finder->query("//div[contains(@class, 'highlight__panel') and contains(@class, 'js-actions-panel')]");

            foreach ($panels as $panel) {
                $panel->parentNode->removeChild($panel);
            }

            if (! empty($this->data['selector'])) {
                // Через SelectorXPathBuilder, а не конкатенацией: selector
                // приходит из формы админки, и кавычка в нём иначе ломает
                // структуру XPath-выражения (XPath injection, CWE-91).
                $nodes = $finder->query(SelectorXPathBuilder::build((string) $this->data['selector']));
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

            $postContent = $this->imageService->replacePictureElements($postContent);
            $postContent = $this->imageService->downloadAndReplaceImages($postContent);

            if (! empty($postContent)) {
                $this->data['content'] = $postContent;
            }

            Log::debug('TranslateService::translate: done', ['title' => $this->data['title'] ?? null]);

        } catch (\Exception $exception) {
            // Исключение до/во время перевода — контент остался (частично) непереведённым
            $this->translationFallbacks++;
            Log::error('TranslateService::translate failed', ['error' => $exception->getMessage()]);
        }

        $this->data['translation_incomplete'] = $this->hasTranslationFallbacks();

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

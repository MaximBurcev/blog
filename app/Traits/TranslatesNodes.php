<?php

namespace App\Traits;

use DOMNode;

trait TranslatesNodes
{
    private function processNode(DOMNode $node, DOMNode|bool $parentNode = false): void
    {
        if (in_array($node->nodeName, ['code', 'pre'])) {
            return;
        }

        if ($node->nodeType === XML_TEXT_NODE) {
            $node->nodeValue = $this->googleTranslate->translate($node->nodeValue);
        }

        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $childNode) {
                $this->processNode($childNode, $node);
            }
        }
    }
}

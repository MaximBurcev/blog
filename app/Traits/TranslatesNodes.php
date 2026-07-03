<?php

namespace App\Traits;

use DOMNode;
use Illuminate\Support\Facades\Log;

trait TranslatesNodes
{
    private function processNode(DOMNode $node, DOMNode|bool $parentNode = false): void
    {
        if (in_array($node->nodeName, ['code', 'pre'])) {
            return;
        }

        if ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue) !== '') {
            $node->nodeValue = $this->translateWithFallback($node->nodeValue);
        }

        if ($node->hasChildNodes()) {
            foreach ($node->childNodes as $childNode) {
                $this->processNode($childNode, $node);
            }
        }
    }

    /**
     * Google Translate иногда молча возвращает пустую строку (без исключения)
     * для длинных узлов — раньше это стирало исходный текст. Теперь при
     * неудаче/пустом ответе оставляем оригинальный текст вместо потери контента.
     */
    private function translateWithFallback(string $text): string
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $translated = $this->googleTranslate->translate($text);

                if (trim((string) $translated) !== '') {
                    return $translated;
                }
            } catch (\Throwable $e) {
                Log::warning('TranslatesNodes: translate attempt failed', [
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                    'text'    => mb_substr($text, 0, 100),
                ]);
            }
        }

        Log::warning('TranslatesNodes: empty translation, keeping original text', [
            'text' => mb_substr($text, 0, 100),
        ]);

        return $text;
    }
}

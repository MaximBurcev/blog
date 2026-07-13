<?php

namespace App\Traits;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;

trait TranslatesNodes
{
    /**
     * Теги, содержимое которых никогда не переводится.
     *
     * @var string[]
     */
    private static array $skipTags = ['code', 'pre', 'script', 'style'];

    /**
     * «Листовые» блочные теги — переводятся целиком одним запросом,
     * с маскировкой инлайн-разметки плейсхолдерами ${n}.
     *
     * @var string[]
     */
    private static array $blockTags = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote',
        'figcaption', 'caption', 'dt', 'dd', 'td', 'th', 'summary',
    ];

    /**
     * Блочные контейнеры: если такой тег есть внутри блока,
     * блок не «листовой» — рекурсивно спускаемся глубже.
     *
     * @var string[]
     */
    private static array $containerTags = [
        'p', 'div', 'ul', 'ol', 'li', 'table', 'blockquote', 'pre', 'figure',
        'section', 'article', 'aside', 'header', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /**
     * Создаёт переводчик с учётом SOCKS5-прокси из config('releases.curl_proxy').
     * Через голый env() читать нельзя: после `artisan config:cache` env()
     * вне config/*.php возвращает null и прокси молча отключается.
     */
    private function makeGoogleTranslate(): GoogleTranslate
    {
        $translator = new GoogleTranslate('ru');

        if ($proxy = config('releases.curl_proxy')) {
            $translator->setOptions(['proxy' => 'socks5://'.$proxy]);
        }

        return $translator;
    }

    private function processNode(DOMNode $node, DOMNode|bool $parentNode = false): void
    {
        if (in_array($node->nodeName, self::$skipTags, true)) {
            return;
        }

        // Листовой блок (абзац, заголовок, пункт списка) переводим целиком:
        // поузловой перевод терял контекст предложения и пробелы вокруг
        // инлайн-тегов — Google переводил фрагменты независимо
        if ($node instanceof DOMElement && $this->isLeafBlock($node)) {
            $this->translateBlock($node);

            return;
        }

        // Fallback для голого текста вне блочных тегов (текст прямо в div и т.п.)
        if ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue) !== '') {
            $node->nodeValue = $this->translateWithFallback($node->nodeValue);

            return;
        }

        if ($node->hasChildNodes()) {
            foreach (iterator_to_array($node->childNodes) as $childNode) {
                $this->processNode($childNode, $node);
            }
        }
    }

    /**
     * Переводит содержимое блочного элемента одним запросом.
     *
     * Инлайн-теги и целые code/pre-фрагменты маскируются плейсхолдерами ${n}
     * (Stichoza preserveParameters защищает их от искажения на стороне Google),
     * после перевода разметка восстанавливается. Если переводчик потерял хотя бы
     * один плейсхолдер — блок остаётся в оригинале, чтобы не сломать разметку.
     */
    private function translateBlock(DOMElement $element): void
    {
        $dom = $element->ownerDocument;

        $inner = '';
        foreach ($element->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }

        [$masked, $map] = $this->maskMarkup($inner);

        // Нечего переводить: пустой блок либо только разметка/код
        if (trim(strtr($masked, array_fill_keys(array_keys($map), ''))) === '') {
            return;
        }

        $this->googleTranslate->preserveParameters('/\$\{\d+\}/');
        $translated = $this->translateWithFallback($masked);

        if ($translated === $masked) {
            return; // fallback вернул оригинал — блок не трогаем
        }

        // Google иногда вставляет пробелы в служебные токены (#{0} → # {0}),
        // и библиотека не может подставить ${n} обратно. Нумерация #{i} и ${i}
        // совпадает (оба присваиваются последовательно), поэтому донормализуем сами
        $translated = preg_replace_callback(
            '/#\s*\{\s*(\d+)\s*\}/',
            fn (array $m) => '${'.$m[1].'}',
            $translated
        );

        foreach (array_keys($map) as $token) {
            if (! str_contains($translated, $token)) {
                Log::warning('TranslatesNodes: placeholder lost, keeping original block', [
                    'token' => $token,
                    'text' => mb_substr($masked, 0, 100),
                ]);

                return;
            }
        }

        $restored = strtr(
            htmlspecialchars($translated, ENT_NOQUOTES, 'UTF-8'),
            $map
        );

        $this->replaceInnerHtml($element, $restored);
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
                    'error' => $e->getMessage(),
                    'text' => mb_substr($text, 0, 100),
                ]);
            }
        }

        Log::warning('TranslatesNodes: empty translation, keeping original text', [
            'text' => mb_substr($text, 0, 100),
        ]);

        return $text;
    }

    private function isLeafBlock(DOMElement $element): bool
    {
        if (! in_array($element->nodeName, self::$blockTags, true)) {
            return false;
        }

        foreach (self::$containerTags as $tag) {
            if ($element->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Маскирует разметку плейсхолдерами ${n}.
     *
     * Сначала целиком элементы, содержимое которых переводить нельзя
     * (code/pre/script/style), затем все остальные теги по отдельности —
     * их внутренний текст остаётся видимым переводчику.
     * Возвращает [текст с плейсхолдерами и декодированными entities, карта токен => разметка].
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function maskMarkup(string $html): array
    {
        $map = [];
        $index = 0;
        $mask = function (string $original) use (&$map, &$index): string {
            $token = '${'.$index++.'}';
            $map[$token] = $original;

            return $token;
        };

        $html = preg_replace_callback(
            '~<(code|pre|script|style)\b[^>]*>.*?</\1>~is',
            fn (array $m) => $mask($m[0]),
            $html
        );

        $html = preg_replace_callback(
            '~<[^>]+>~',
            fn (array $m) => $mask($m[0]),
            $html
        );

        return [html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $map];
    }

    private function replaceInnerHtml(DOMElement $element, string $html): void
    {
        $tmp = new DOMDocument;
        if (! @$tmp->loadHTML('<?xml encoding="utf-8" ?><body>'.$html.'</body>')) {
            Log::warning('TranslatesNodes: failed to parse translated block, keeping original', [
                'html' => mb_substr($html, 0, 100),
            ]);

            return;
        }

        $body = $tmp->getElementsByTagName('body')->item(0);
        if (! $body) {
            return;
        }

        while ($element->firstChild) {
            $element->removeChild($element->firstChild);
        }

        foreach (iterator_to_array($body->childNodes) as $child) {
            $element->appendChild($element->ownerDocument->importNode($child, true));
        }
    }
}

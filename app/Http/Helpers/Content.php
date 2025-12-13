<?php

namespace App\Http\Helpers;

class Content
{
    public static function extractHljsCodeText(string $htmlContent): array
    {

        $dom = new \DOMDocument();
        // Подавляем предупреждения из-за некорректного HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        // Найти все <code>, у которых класс содержит "hljs"
        $nodes = $xpath->query('//code[contains(@class, "hljs")]');


        $texts = [];
        foreach ($nodes as $node) {
            // Получаем текстовое содержимое (без тегов)
            $texts[] = $node->textContent;
        }

        dd($texts);

        return $texts;
    }

    public static function cleanCodeTags(string $htmlContent): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        // Оборачиваем в <div>, чтобы избежать добавления <html><body>
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $htmlContent . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        // Находим все <code> (можно уточнить по классу, но в вашем случае все code — с подсветкой)
        $codeNodes = $xpath->query('//code');

        foreach ($codeNodes as $code) {
            // Получаем чистый текст (без тегов)
            $text = $code->textContent;

            // Очищаем содержимое узла
            while ($code->hasChildNodes()) {
                $code->removeChild($code->firstChild);
            }

            // Вставляем обратно чистый текст
            $code->textContent = $text;
        }

        // Извлекаем содержимое <div>, чтобы не возвращать обёртку
        $cleanHtml = '';
        foreach ($dom->documentElement->childNodes as $child) {
            $cleanHtml .= $dom->saveHTML($child);
        }

        return $cleanHtml;
    }
}


<?php

namespace App\Service\Translation;

use App\Traits\TranslatesNodes;
use DOMDocument;
use Stichoza\GoogleTranslate\GoogleTranslate;

/**
 * Прежний перевод через неофициальный скрейпер translate.google.com.
 *
 * Оставлен запасным движком: Gemini живёт за сетью и региональными
 * ограничениями, и его недоступность не должна останавливать разбор статей.
 * Качество заметно ниже — скрейпер не видит HTML и переводит абзацами, поэтому
 * вокруг него и построена вся маскировка разметки плейсхолдерами в
 * TranslatesNodes, — но полстатьи по-русски лучше, чем ничего.
 */
class GoogleScraperTranslator implements Translator
{
    use TranslatesNodes;

    /**
     * Объявлено явно: трейт свойство только использует, а динамическое
     * создание полей в PHP 8.2+ помечено deprecated.
     */
    private GoogleTranslate $googleTranslate;

    public function __construct()
    {
        $this->googleTranslate = $this->makeGoogleTranslate();
    }

    public function name(): string
    {
        return 'google';
    }

    public function translateHtml(string $html): TranslationResult
    {
        if (trim($html) === '') {
            return TranslationResult::success($html, $this->name());
        }

        $this->translationFallbacks = 0;

        $dom = new DOMDocument;

        if (! @$dom->loadHTML(
            '<?xml encoding="utf-8" ?><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        )) {
            return TranslationResult::failure($html);
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        if (! $body) {
            return TranslationResult::failure($html);
        }

        foreach (iterator_to_array($body->childNodes) as $node) {
            $this->processNode($node);
        }

        $translated = '';
        foreach ($body->childNodes as $node) {
            $translated .= $dom->saveHTML($node);
        }

        if (trim($translated) === '') {
            return TranslationResult::failure($html);
        }

        // Потерянные при переводе блоки не делают результат негодным: трейт
        // оставляет такие куски в оригинале, остальная статья переведена.
        // Но пометить статью надо — иначе плашка «перевод неполный» в админке
        // никогда не покажется, и полуанглийский текст уйдёт в публикацию как
        // готовый.
        return new TranslationResult(
            $translated,
            $this->name(),
            failed: false,
            partial: $this->hasTranslationFallbacks(),
        );
    }

    public function translateText(string $text): TranslationResult
    {
        if (trim($text) === '') {
            return TranslationResult::success($text, $this->name());
        }

        $this->translationFallbacks = 0;

        $translated = $this->translateWithFallback($text);

        if ($this->hasTranslationFallbacks()) {
            return TranslationResult::failure($text);
        }

        return TranslationResult::success($translated, $this->name());
    }
}

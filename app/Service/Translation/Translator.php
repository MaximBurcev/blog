<?php

namespace App\Service\Translation;

/**
 * Движок перевода на русский.
 *
 * Два метода, а не один, потому что фрагменты разной природы: HTML-кусок
 * статьи нужно вернуть с сохранённой разметкой, а заголовок — это плоская
 * строка, и оборачивать её в разметку незачем.
 */
interface Translator
{
    /**
     * Переводит HTML-фрагмент, сохраняя разметку и не трогая код.
     */
    public function translateHtml(string $html): TranslationResult;

    /**
     * Переводит простой текст без разметки (заголовок, подпись).
     */
    public function translateText(string $text): TranslationResult;

    /**
     * Короткое имя движка для логов и пометки в БД.
     */
    public function name(): string;
}

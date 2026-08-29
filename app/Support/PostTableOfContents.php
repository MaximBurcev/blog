<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Оглавление статьи: собирается из заголовков h2/h3 тела поста при рендере
 * страницы, а не при сохранении — колонку content перезаписывают и парсер,
 * и админка, и хранить вторую («размеченную») версию текста значило бы
 * держать две копии, которые неизбежно разъедутся.
 *
 * Якоря проставляются в разметку здесь же: заголовку без id назначается
 * `section-N` (N — порядковый номер заголовка в тексте, нумерация сплошная
 * и потому стабильная между рендерами). Транслит кириллицы не нужен: id —
 * служебный адрес, а не человекочитаемый URL. Существующий id сохраняется —
 * разобранные статьи иногда приходят со своими якорями, и ломать их нельзя.
 *
 * Блоки кода разбору не мешают: их содержимое в content экранировано, для
 * DOM это текст, а не разметка; при обратной сериализации сущности
 * восстанавливаются как были.
 */
class PostTableOfContents
{
    /**
     * Меньше заголовков — оглавление не показываем: для короткой заметки
     * блок «Содержание» длиннее самого текста.
     */
    private const MIN_HEADINGS = 3;

    /** @var array{items: array<int, array{id: string, title: string, level: int}>, content: string}|null */
    private ?array $processed = null;

    public function __construct(private readonly string $html) {}

    /**
     * Пункты оглавления; пусто, если заголовков меньше MIN_HEADINGS.
     *
     * @return array<int, array{id: string, title: string, level: int}>
     */
    public function items(): array
    {
        return $this->process()['items'];
    }

    /**
     * Тело статьи с проставленными якорями; без изменений, если оглавления
     * не будет.
     */
    public function content(): string
    {
        return $this->process()['content'];
    }

    /**
     * @return array{items: array<int, array{id: string, title: string, level: int}>, content: string}
     */
    private function process(): array
    {
        return $this->processed ??= $this->doProcess();
    }

    /**
     * @return array{items: array<int, array{id: string, title: string, level: int}>, content: string}
     */
    private function doProcess(): array
    {
        $dom = new DOMDocument;

        // Ошибки разбора глушим: content — чужой HTML, и предупреждения о
        // незнакомых тегах HTML5 для нас шум, а не проблема.
        $internalErrors = libxml_use_internal_errors(true);

        // Оборачиваем в полный документ с meta charset: так DOMDocument и
        // читает строку как UTF-8, и — в отличие от трюка с xml-объявлением —
        // отдаёт saveHTML() обратно в UTF-8, а не простыней сущностей
        // (HTML-ENTITIES выпилен из PHP 8.2). Флаг NOIMPLIED здесь нельзя:
        // с ним разбор обрывается на первом же пустом теге.
        $dom->loadHTML(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'.$this->html.'</body></html>'
        );

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $headings = (new DOMXPath($dom))->query('//h2 | //h3');

        if ($headings === false || $headings->length < self::MIN_HEADINGS) {
            return ['items' => [], 'content' => $this->html];
        }

        $items = [];
        $position = 0;

        foreach ($headings as $heading) {
            /** @var DOMElement $heading */
            $position++;
            $id = $heading->getAttribute('id');

            if ($id === '') {
                $id = 'section-'.$position;
                $heading->setAttribute('id', $id);
            }

            $items[] = [
                'id' => $id,
                'title' => trim($heading->textContent),
                'level' => (int) substr($heading->nodeName, 1),
            ];
        }

        // Сериализуем обратно только содержимое <body> — наша обёртка
        // (html/head/meta) на страницу уехать не должна.
        $content = '';
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $content .= $dom->saveHTML($node);
        }

        return ['items' => $items, 'content' => $content];
    }
}

<?php

namespace App\Service\Translation;

use DOMDocument;

/**
 * Проверяет, можно ли доверять переводу, который вернула модель.
 *
 * Скрейпер был туп, но предсказуем: он не умел ничего, кроме подстановки строк.
 * LLM умеет всё, в том числе переписать код, выбросить абзац или вернуть текст
 * с извинениями вместо разметки. Поэтому ответ проверяется перед сохранением, а
 * не после жалобы читателя.
 *
 * Проверок ровно три, и каждая ловит то, что реально случалось при прогоне:
 * пустой ответ, разрушенная разметка, тронутый код.
 */
class TranslatedHtmlValidator
{
    /**
     * Модель нередко заворачивает ответ в markdown-блок, даже когда просили
     * этого не делать. Это не порча разметки, а формат ответа, поэтому обёртка
     * снимается до проверок, а не приводит к отказу.
     */
    public function unwrap(string $html): string
    {
        $trimmed = trim($html);

        // Перенос перед закрывающим фенсом необязателен: модели регулярно
        // отвечают «```html\n<p>…</p>```» без него, и строгий шаблон оставлял
        // бэктики прямо в тексте статьи.
        if (preg_match('/^```(?:html)?\s*\n?(.*?)\s*```$/s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    /**
     * @return string|null null — перевод годен; строка — причина отказа (в лог)
     */
    public function reasonToReject(string $original, string $translated): ?string
    {
        if (trim($translated) === '') {
            return 'пустой ответ';
        }

        if (! $this->parses($translated)) {
            return 'ответ не разбирается как HTML';
        }

        $originalText = $this->visibleText($original);
        $translatedText = $this->visibleText($translated);

        /*
         * Отказ модели («Извините, я не могу…») — валидный HTML без кода, и
         * проверка кода его пропускает. Ловим по объёму: осмысленный перевод
         * не бывает втрое короче оригинала. Тем же условием отсекается
         * пересказ вместо перевода и выброшенная половина статьи.
         *
         * Верхняя граница нужна не меньше: разговорчивый ответ вида «Вот
         * перевод, а ещё я заметил…» раздувает текст.
         */
        if ($originalText !== '') {
            $ratio = mb_strlen($translatedText) / mb_strlen($originalText);

            if ($ratio < 0.5) {
                return sprintf('перевод короче оригинала в %.1f раза', 1 / max($ratio, 0.01));
            }

            if ($ratio > 2.5) {
                return sprintf('перевод длиннее оригинала в %.1f раза', $ratio);
            }
        }

        /*
         * Русского текста нет вовсе — значит модель вернула оригинал, отказ
         * по-английски или служебное сообщение. Порог низкий намеренно: в
         * статье полно английских терминов и кода, и требовать высокой доли
         * кириллицы нельзя.
         */
        if ($originalText !== '' && ! preg_match('/\p{Cyrillic}/u', $translatedText)) {
            return 'в ответе нет кириллицы';
        }

        /*
         * Структура: сколько блоков было, столько примерно и должно остаться.
         * Допуск нужен — модель законно схлопывает пустой абзац или разбивает
         * длинный, — но потеря половины блоков это уже не перевод.
         */
        $originalBlocks = $this->countBlocks($original);
        $translatedBlocks = $this->countBlocks($translated);

        if ($originalBlocks >= 4 && $translatedBlocks < $originalBlocks * 0.6) {
            return sprintf(
                'потеряны блоки текста (было %d, стало %d)',
                $originalBlocks,
                $translatedBlocks
            );
        }

        // Код обязан доехать байт в байт. Перевод содержимого <pre>/<code> —
        // самая дорогая ошибка: читатель копирует такой фрагмент и получает
        // синтаксическую ошибку, а не работающий пример.
        $originalCode = $this->codeFragments($original);
        $translatedCode = $this->codeFragments($translated);

        if ($originalCode !== $translatedCode) {
            return sprintf(
                'код изменён (было фрагментов: %d, стало: %d)',
                count($originalCode),
                count($translatedCode)
            );
        }

        return null;
    }

    /**
     * Содержимое всех code/pre в порядке появления.
     *
     * Сравниваем именно текст, а не разметку целиком: атрибуты вроде
     * class="language-php" модель сохраняет, но полагаться на побайтовое
     * совпадение всего тега незачем — важно, что сам код не тронут.
     *
     * @return string[]
     */
    private function codeFragments(string $html): array
    {
        if (! preg_match_all('~<(code|pre)\b[^>]*>(.*?)</\1>~is', $html, $matches)) {
            return [];
        }

        return array_map(
            // Пробелы по краям нормализуем: DOM-обработка на нашей же стороне
            // может добавить перевод строки, и это не изменение кода.
            static fn (string $fragment): string => trim(html_entity_decode(
                strip_tags($fragment),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )),
            $matches[2]
        );
    }

    /**
     * Видимый читателю текст: без разметки и без кода.
     *
     * Код исключается намеренно — он не переводится, и его объём одинаков с
     * обеих сторон. Считая его, мы бы размывали ровно ту разницу, которую
     * измеряем: у статьи, где кода больше, чем прозы, отказ модели прошёл бы
     * проверку объёма.
     */
    private function visibleText(string $html): string
    {
        $withoutCode = preg_replace('~<(code|pre)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;

        return trim(preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($withoutCode), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        ) ?? '');
    }

    private function countBlocks(string $html): int
    {
        return preg_match_all('~<(p|h[1-6]|li|blockquote|td)\b~i', $html);
    }

    private function parses(string $html): bool
    {
        $dom = new DOMDocument;

        return @$dom->loadHTML(
            '<?xml encoding="utf-8" ?><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
    }
}

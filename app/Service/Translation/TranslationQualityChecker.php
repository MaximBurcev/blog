<?php

namespace App\Service\Translation;

/**
 * Эвристики качества готового перевода — второй контур после валидатора.
 *
 * TranslatedHtmlValidator решает, принимать ли ответ модели, и работает ДО
 * сохранения, пока есть шанс переспросить или уйти на запасной движок. Этот
 * класс смотрит на итоговую пару «оригинал → перевод» уже по факту и отвечает
 * на другой вопрос: стоит ли показать статью живому редактору. Поэтому здесь
 * не отказ, а пометка — её результат уходит в translation_incomplete и в
 * подсказку у иконки «Перевод» в админке.
 *
 * Проверки ловят то, что движки отрабатывают «успешно»: часть блоков
 * осталась в оригинале, текст оборвался на середине, перевод вдвое короче
 * исходника. Все три случая реально встречались у скрейпера и у LLM при
 * исчерпании квоты посреди статьи.
 */
class TranslationQualityChecker
{
    public function __construct(
        private readonly float $maxUntranslatedRatio = 0.3,
        private readonly int $minBlockChars = 20,
        private readonly float $minLengthRatio = 0.5,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxUntranslatedRatio: (float) config('translation.quality.max_untranslated_ratio', 0.3),
            minBlockChars: (int) config('translation.quality.min_block_chars', 20),
            minLengthRatio: (float) config('translation.quality.min_length_ratio', 0.5),
        );
    }

    /**
     * @return string|null null — поводов для ревью нет; строка — причина,
     *                     понятная редактору (показывается в админке как есть)
     */
    public function reviewReason(string $originalHtml, string $translatedHtml): ?string
    {
        $originalText = $this->visibleText($originalHtml);
        $translatedText = $this->visibleText($translatedHtml);

        // Пустой перевод — это не «низкое качество», а несостоявшийся перевод:
        // его помечают правила движков (failed / translated_by = none), и
        // дублировать их здесь незачем.
        if ($originalText === '' || $translatedText === '') {
            return null;
        }

        /*
         * Доля текстовых блоков, оставшихся без кириллицы. Скрейпер теряет
         * блоки штатно (429 на середине статьи), LLM — при обрыве по бюджету:
         * статья в итоге наполовину английская, хотя движок отчитался об
         * успехе. Короткие блоки (подписи, сноски, однострочники) не считаем —
         * «OK», «Note:» и номера версий законно остаются латиницей.
         */
        $considered = 0;
        $untranslated = 0;

        foreach ($this->textBlocks($translatedHtml) as $blockText) {
            if (mb_strlen($blockText) < $this->minBlockChars) {
                continue;
            }

            $considered++;

            if (! preg_match('/\p{Cyrillic}/u', $blockText)) {
                $untranslated++;
            }
        }

        if ($considered > 0 && $untranslated / $considered > $this->maxUntranslatedRatio) {
            return sprintf('не переведены %d из %d текстовых блоков', $untranslated, $considered);
        }

        /*
         * Перевод подозрительно короче оригинала — обрыв ответа или потерянный
         * кусок. Сравнение относительное намеренно: у новости и у длинной
         * статьи «нормальный» объём свой, и абсолютный порог стрелял бы по
         * всем коротким новостям.
         */
        $ratio = mb_strlen($translatedText) / mb_strlen($originalText);

        if ($ratio < $this->minLengthRatio) {
            return sprintf(
                'перевод короче оригинала в %.1f раза — похоже на обрыв или потерю части текста',
                1 / max($ratio, 0.01)
            );
        }

        /*
         * Текст оборван: оригинал заканчивается знаком конца предложения, а
         * перевод — нет. Статья, обрезанная по лимиту токенов, рвётся на
         * полуслове, и это самый заметный для читателя дефект. Концовку
         * сравниваем с оригиналом: если исходник сам обрывается без точки
         * (стиль источника, подпись, ссылка в конце), это не обрыв перевода.
         *
         * Статья, заканчивающаяся блоком кода, — норма тоже: последний абзац
         * прозы там честно вводит пример («Вот как это выглядит:»), и
         * требовать от него точки нельзя.
         */
        if (! $this->endsWithCodeBlock($translatedHtml)
            && $this->endsWithSentenceEnd($originalText)
            && ! $this->endsWithSentenceEnd($translatedText)) {
            return 'текст оборван: в отличие от оригинала, не заканчивается знаком конца предложения';
        }

        return null;
    }

    private function endsWithSentenceEnd(string $text): bool
    {
        return (bool) preg_match('/[.!?…»"\')\]]$/u', $text);
    }

    /**
     * Текстовое содержимое блоков (p, h1-6, li, blockquote, td) в порядке
     * появления — без разметки и без кода, который не переводится.
     *
     * @return string[]
     */
    private function textBlocks(string $html): array
    {
        if (! preg_match_all('~<(p|h[1-6]|li|blockquote|td)\b[^>]*>(.*?)</\1>~is', $html, $matches)) {
            return [];
        }

        return array_map($this->visibleText(...), $matches[2]);
    }

    /**
     * Видимый читателю текст: без разметки и без содержимого code/pre.
     * Тот же подход, что в TranslatedHtmlValidator::visibleText, — код не
     * переводится, и его объём только размывал бы измеряемую разницу.
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

    private function endsWithCodeBlock(string $html): bool
    {
        return (bool) preg_match('~</(pre|code)>\s*$~i', $html);
    }
}

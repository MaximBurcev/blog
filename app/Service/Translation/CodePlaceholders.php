<?php

namespace App\Service\Translation;

/**
 * Прячет содержимое <code>/<pre> от модели плейсхолдерами вида ⟦CODEn⟧.
 *
 * Промпт и раньше прямо запрещал переводить код, но модель всё равно
 * переписывала комментарий внутри блока (пост 352, thephp.foundation,
 * 25.09.2026) — словесная инструкция не гарантирует соблюдения ни одной из
 * моделей цепочки. Плейсхолдер надёжнее: модели физически нечего портить,
 * реальный код возвращается на место побайтово после ответа.
 */
final class CodePlaceholders
{
    private const PATTERN = '~<(code|pre)\b[^>]*>.*?</\1>~is';

    /** Токен ищет TranslatedHtmlValidator, проверяя, что плейсхолдер не потерян. */
    public const TOKEN_PATTERN = '~⟦CODE\d+⟧~u';

    /** @var string[] */
    private array $fragments = [];

    public function mask(string $html): string
    {
        return preg_replace_callback(
            self::PATTERN,
            function (array $match): string {
                $token = $this->token(count($this->fragments));
                $this->fragments[] = $match[0];

                return $token;
            },
            $html
        ) ?? $html;
    }

    /**
     * Возвращает исходный код на место токенов.
     *
     * Токен, которого модель не вернула, — забракованный ответ: за этим следит
     * TranslatedHtmlValidator ещё до вызова restore(), поэтому здесь пропажа
     * не проверяется повторно.
     */
    public function restore(string $html): string
    {
        foreach ($this->fragments as $index => $fragment) {
            $html = str_replace($this->token($index), $fragment, $html);
        }

        return $html;
    }

    private function token(int $index): string
    {
        return '⟦CODE'.$index.'⟧';
    }
}

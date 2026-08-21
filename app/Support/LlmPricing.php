<?php

namespace App\Support;

/**
 * Перевод токенов в деньги по прайсу из config/translation.php.
 *
 * Отдельно от модели LlmCall намеренно: в таблице лежат токены, то есть факт,
 * а цена — внешнее обстоятельство, которое меняется без нашего участия. Стоит
 * Google поправить прайс, и достаточно одной строки конфига, чтобы вся история
 * пересчиталась; храни мы деньги колонкой, прошлое осталось бы посчитанным по
 * старой цене навсегда.
 */
final class LlmPricing
{
    /**
     * Цена не задана — виджету честнее сказать это, чем показать ноль.
     *
     * Ноль рублей и «мы не знаем, сколько это стоит» — разные утверждения, и
     * первое выглядит убедительнее ровно настолько, насколько оно неверно.
     */
    public static function isKnown(string $model): bool
    {
        return self::rates($model) !== null;
    }

    /**
     * Стоимость в долларах.
     *
     * Выходные токены и «размышления» тарифицируются по одной цене, поэтому
     * вызывающий передаёт их суммой: разделение между ними интересно как
     * настройка thinking_level, а не как статья расходов.
     */
    public static function costUsd(string $model, int $promptTokens, int $outputTokens): ?float
    {
        $rates = self::rates($model);

        if ($rates === null) {
            return null;
        }

        // Прайс задаётся за миллион токенов — так его публикует Google, и так
        // же его проще сверять глазами с страницей тарифов.
        return ($promptTokens * $rates['input'] + $outputTokens * $rates['output']) / 1_000_000;
    }

    /**
     * @return array{input: float, output: float}|null
     */
    private static function rates(string $model): ?array
    {
        $prices = (array) config('translation.gemini.prices', []);
        $rates = $prices[$model] ?? null;

        if (! is_array($rates) || ! isset($rates['input'], $rates['output'])) {
            return null;
        }

        return ['input' => (float) $rates['input'], 'output' => (float) $rates['output']];
    }
}

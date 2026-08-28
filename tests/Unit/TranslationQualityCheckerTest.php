<?php

namespace Tests\Unit;

use App\Service\Translation\TranslationQualityChecker;
use Tests\TestCase;

/**
 * Эвристики качества перевода (TranslationQualityChecker): пометка «требует
 * ревью» по паре «оригинал → перевод». Проверяем все три причины (блоки без
 * кириллицы, подозрительно короткий перевод, обрыв текста) и граничные
 * случаи, где ревью НЕ нужно: короткая новость, концовка блоком кода,
 * латинские коротыши вроде «OK».
 */
class TranslationQualityCheckerTest extends TestCase
{
    private function checker(): TranslationQualityChecker
    {
        return TranslationQualityChecker::fromConfig();
    }

    private function paragraph(string $text): string
    {
        return '<p>'.$text.'</p>';
    }

    public function test_full_translation_needs_no_review(): void
    {
        $original = $this->paragraph('Laravel queues provide a unified API across a variety of different queue backends.')
            .$this->paragraph('Queues allow you to defer the processing of a time consuming task until a later time.');

        $translated = $this->paragraph('Очереди Laravel предоставляют единый API для самых разных бэкендов очередей.')
            .$this->paragraph('Очереди позволяют отложить выполнение трудоёмкой задачи на более позднее время.');

        $this->assertNull($this->checker()->reviewReason($original, $translated));
    }

    public function test_untranslated_blocks_above_threshold_require_review(): void
    {
        $original = $this->paragraph('First paragraph of the article with enough text to be counted as a real block.')
            .$this->paragraph('Second paragraph of the article with enough text to be counted as a real block.')
            .$this->paragraph('Third paragraph of the article with enough text to be counted as a real block.');

        // Два из трёх блоков остались на английском.
        $translated = $this->paragraph('First paragraph of the article with enough text to be counted as a real block.')
            .$this->paragraph('Second paragraph of the article with enough text to be counted as a real block.')
            .$this->paragraph('Третий абзац статьи с достаточным количеством текста, чтобы считаться блоком.');

        $reason = $this->checker()->reviewReason($original, $translated);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('не переведены 2 из 3', $reason);
    }

    public function test_short_latin_blocks_do_not_count_as_untranslated(): void
    {
        $original = $this->paragraph('A long enough paragraph of the original article that certainly needs a proper translation.')
            .$this->paragraph('Note:')
            .$this->paragraph('Another long enough paragraph of the original article that also needs a proper translation.');

        $translated = $this->paragraph('Достаточно длинный абзац оригинальной статьи, которому точно нужен нормальный перевод.')
            .$this->paragraph('Note:')
            .$this->paragraph('Ещё один достаточно длинный абзац оригинальной статьи, которому тоже нужен перевод.');

        $this->assertNull($this->checker()->reviewReason($original, $translated));
    }

    public function test_suspiciously_short_translation_requires_review(): void
    {
        $original = $this->paragraph(str_repeat('This is a long original paragraph full of meaningful words. ', 5));
        // Осталась треть текста — обрыв или потерянный кусок.
        $translated = $this->paragraph('Это длинный абзац оригинала.');

        $reason = $this->checker()->reviewReason($original, $translated);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('короче оригинала', $reason);
    }

    public function test_short_news_fully_translated_needs_no_review(): void
    {
        // Короткая новость: относительное сравнение не должно по ней стрелять.
        $original = $this->paragraph('Laravel 12.5 released with a new queue metrics driver.');
        $translated = $this->paragraph('Вышел Laravel 12.5 с новым драйвером метрик очередей.');

        $this->assertNull($this->checker()->reviewReason($original, $translated));
    }

    public function test_truncated_text_without_final_punctuation_requires_review(): void
    {
        $original = $this->paragraph('The queue worker continues processing jobs until the stop signal arrives.')
            .$this->paragraph('After the restart the supervisor brings all of the workers back online again.');

        // Текст рвётся на полуслове — обрезка по лимиту токенов.
        $translated = $this->paragraph('Воркер очереди продолжает обрабатывать задачи, пока не придёт сигнал остановки.')
            .$this->paragraph('После перезапуска супервизор поднимает всех вор');

        $reason = $this->checker()->reviewReason($original, $translated);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('оборван', $reason);
    }

    public function test_article_ending_with_code_block_is_not_truncation(): void
    {
        $original = $this->paragraph('Run the worker like this:')
            .'<pre><code>php artisan queue:work --tries=3</code></pre>';

        $translated = $this->paragraph('Запустите воркер так:')
            .'<pre><code>php artisan queue:work --tries=3</code></pre>';

        $this->assertNull($this->checker()->reviewReason($original, $translated));
    }

    public function test_original_without_final_punctuation_is_not_truncation(): void
    {
        // Исходник сам кончается без точки (стиль источника) — перевод не
        // обязан быть пунктуальнее оригинала.
        $original = $this->paragraph('Read the full changelog on the releases page');
        $translated = $this->paragraph('Полный список изменений читайте на странице релизов');

        $this->assertNull($this->checker()->reviewReason($original, $translated));
    }

    public function test_empty_translation_is_not_this_checkers_concern(): void
    {
        // Пустой перевод помечают правила движков (failed / none), эвристики
        // оценивают качество состоявшегося перевода и здесь молчат.
        $this->assertNull($this->checker()->reviewReason('<p>Some original text.</p>', ''));
    }

    public function test_thresholds_are_configurable(): void
    {
        // Половина блоков без перевода: при пороге 0.3 — ревью, при 0.6 — нет.
        $original = $this->paragraph('First paragraph of the article with enough text to be counted as a real block.')
            .$this->paragraph('Second paragraph of the article with enough text to be counted as a real block.');

        $translated = $this->paragraph('First paragraph of the article with enough text to be counted as a real block.')
            .$this->paragraph('Второй абзац статьи с достаточным количеством текста, чтобы считаться блоком.');

        $strict = new TranslationQualityChecker(maxUntranslatedRatio: 0.3);
        $lenient = new TranslationQualityChecker(maxUntranslatedRatio: 0.6);

        $this->assertNotNull($strict->reviewReason($original, $translated));
        $this->assertNull($lenient->reviewReason($original, $translated));
    }
}

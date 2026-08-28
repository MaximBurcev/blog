<?php

namespace Tests\Unit;

use App\Models\Post;
use Tests\TestCase;

/**
 * Время чтения — подсчёт слов в content (без HTML-тегов) / 200 слов в
 * минуту, минимум 1 минута. Отдельно проверяем русское склонение
 * ("минута"/"минуты"/"минут").
 */
class PostReadingTimeTest extends TestCase
{
    private function makePost(string $content): Post
    {
        return new Post(['content' => $content]);
    }

    public function test_short_content_rounds_up_to_one_minute(): void
    {
        $post = $this->makePost(str_repeat('слово ', 10));

        $this->assertSame(1, $post->readingTimeMinutes());
    }

    public function test_two_hundred_words_is_one_minute(): void
    {
        $post = $this->makePost(str_repeat('слово ', 200));

        $this->assertSame(1, $post->readingTimeMinutes());
    }

    public function test_rounds_up_partial_minutes(): void
    {
        $post = $this->makePost(str_repeat('слово ', 201));

        $this->assertSame(2, $post->readingTimeMinutes());
    }

    public function test_html_tags_are_stripped_before_counting(): void
    {
        $post = $this->makePost('<p>'.str_repeat('слово ', 400).'</p>');

        $this->assertSame(2, $post->readingTimeMinutes());
    }

    public function test_empty_content_is_still_one_minute(): void
    {
        $post = $this->makePost('');

        $this->assertSame(1, $post->readingTimeMinutes());
    }

    /**
     * Подсчёт слов общий с JSON-LD (Post::wordCount). Юникодный паттерн, а
     * не str_word_count(): тот по умолчанию считает буквами только ASCII и
     * на русском тексте возвращал единицы — этот мусор уезжал в wordCount
     * разметки страницы поста.
     */
    public function test_word_count_counts_cyrillic_words(): void
    {
        $post = $this->makePost('<p>Привет, мир! Это проверка 2 слов.</p>');

        $this->assertSame(6, $post->wordCount());
    }

    public function test_label_declines_minuta_for_one(): void
    {
        $post = $this->makePost(str_repeat('слово ', 10));

        $this->assertSame('1 минута чтения', $post->readingTimeLabel());
    }

    public function test_label_declines_minuty_for_two_to_four(): void
    {
        $post = $this->makePost(str_repeat('слово ', 401)); // 3 минуты

        $this->assertSame('3 минуты чтения', $post->readingTimeLabel());
    }

    public function test_label_declines_minut_for_five_and_more(): void
    {
        $post = $this->makePost(str_repeat('слово ', 801)); // 5 минут

        $this->assertSame('5 минут чтения', $post->readingTimeLabel());
    }

    public function test_label_declines_minut_for_eleven(): void
    {
        // 11 — исключение из общего правила (оканчивается на 1, но не "минута")
        $post = $this->makePost(str_repeat('слово ', 2001)); // 11 минут

        $this->assertSame('11 минут чтения', $post->readingTimeLabel());
    }
}

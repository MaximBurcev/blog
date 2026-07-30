<?php

namespace Tests\Unit;

use App\Models\Post;
use Tests\TestCase;

class PostExcerptTest extends TestCase
{
    public function test_excerpt_strips_tags_and_decodes_entities(): void
    {
        $post = new Post(['content' => '<p>Tom &amp; Jerry</p>']);

        $this->assertSame('Tom & Jerry', $post->excerpt());
    }

    public function test_excerpt_collapses_whitespace(): void
    {
        $post = new Post(['content' => "Line one\n\n   Line   two"]);

        $this->assertSame('Line one Line two', $post->excerpt());
    }

    /**
     * Листинги кода не должны попадать в meta description: раньше оттуда
     * приезжали куски вида «…воссоздадим оператор ниже:$paymentS…».
     */
    public function test_excerpt_drops_code_blocks(): void
    {
        $post = new Post(['content' => '<p>Пример:</p><pre><code>$payment = match($x) {};</code></pre><p>Конец.</p>']);

        $this->assertSame('Пример: Конец.', $post->excerpt());
    }

    /**
     * strip_tags() склеивал текст соседних блоков («в PHP 8.Ключевое слово»),
     * поэтому теги заменяются пробелом.
     */
    public function test_excerpt_does_not_glue_neighbouring_blocks(): void
    {
        $post = new Post(['content' => '<p>появилось в PHP 8.</p><h2>Ключевое слово</h2>']);

        $this->assertSame('появилось в PHP 8. Ключевое слово', $post->excerpt());
    }

    public function test_excerpt_respects_length(): void
    {
        $post = new Post(['content' => str_repeat('a', 300)]);

        $excerpt = $post->excerpt(160);

        // 160 символов текста + многоточие: разрывать нечего, пробелов нет
        $this->assertSame(161, mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
    }

    public function test_excerpt_cuts_on_word_boundary(): void
    {
        $post = new Post(['content' => str_repeat('слово ', 60)]);

        $excerpt = $post->excerpt(50);

        $this->assertStringEndsWith('слово…', $excerpt);
        $this->assertLessThanOrEqual(51, mb_strlen($excerpt));
    }
}

<?php

namespace Tests\Unit;

use App\Models\Post;
use Tests\TestCase;

class PostExcerptTest extends TestCase
{
    public function test_excerpt_strips_tags_and_decodes_entities(): void
    {
        $post = new Post(['content' => '<p>Tom &amp; Jerry <code>&lt;html&gt;</code></p>']);

        $this->assertSame('Tom & Jerry <html>', $post->excerpt());
    }

    public function test_excerpt_collapses_whitespace(): void
    {
        $post = new Post(['content' => "Line one\n\n   Line   two"]);

        $this->assertSame('Line one Line two', $post->excerpt());
    }

    public function test_excerpt_respects_length(): void
    {
        $post = new Post(['content' => str_repeat('a', 300)]);

        $this->assertSame(163, mb_strlen($post->excerpt(160)));
        $this->assertStringEndsWith('...', $post->excerpt(160));
    }
}

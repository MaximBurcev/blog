<?php

namespace Tests\Unit;

use App\Models\Post;
use Tests\TestCase;

/**
 * Склонение счётчика комментариев ("комментарий"/"комментария"/"комментариев").
 * Тот же паттерн, что у Post::pluralViews()/pluralMinutes() — регрессия на
 * жёстко зашитое "Комментария" на странице поста (не склонялось даже для 0).
 */
class PostCommentsLabelTest extends TestCase
{
    private function makePost(): Post
    {
        return new Post;
    }

    public function test_label_declines_kommentariev_for_zero(): void
    {
        $this->assertSame('0 комментариев', $this->makePost()->commentsLabel(0));
    }

    public function test_label_declines_kommentariy_for_one(): void
    {
        $this->assertSame('1 комментарий', $this->makePost()->commentsLabel(1));
    }

    public function test_label_declines_kommentariya_for_two_to_four(): void
    {
        $this->assertSame('3 комментария', $this->makePost()->commentsLabel(3));
    }

    public function test_label_declines_kommentariev_for_five_and_more(): void
    {
        $this->assertSame('5 комментариев', $this->makePost()->commentsLabel(5));
    }

    public function test_label_declines_kommentariev_for_eleven(): void
    {
        // 11 — исключение из общего правила (оканчивается на 1, но не "комментарий")
        $this->assertSame('11 комментариев', $this->makePost()->commentsLabel(11));
    }

    public function test_label_declines_kommentariya_for_twenty_two(): void
    {
        $this->assertSame('22 комментария', $this->makePost()->commentsLabel(22));
    }
}

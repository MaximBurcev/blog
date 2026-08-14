<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * До появления resources/views/errors/404.blade.php битый адрес отдавал
 * служебную страницу Laravel — чёрный экран без шапки, футера и метатегов.
 */
class NotFoundPageTest extends TestCase
{
    public function test_missing_page_renders_site_layout(): void
    {
        $response = $this->get('/no-such-page');

        $response->assertNotFound();
        // Шапка и футер тут ничего не доказывают: их рисует layouts.main, и
        // проверка осталась бы зелёной даже с пустым @section('content').
        // Смотрим на то, что есть только в самом шаблоне ошибки.
        $response->assertSee('Страница не найдена');
        $response->assertSee('Адрес набран с ошибкой', false);
        $response->assertSee('Что ищете?', false);
        $response->assertSee(route('category.index'), false);
    }

    /**
     * Страница ошибки обязана оставаться ошибкой: отдай она 200, поисковик
     * начнёт индексировать битые адреса как обычные страницы (soft 404).
     */
    public function test_missing_post_keeps_not_found_status(): void
    {
        $this->get(route('post.show', 'no-such-post'))->assertNotFound();
    }

    /**
     * Метаданные страницам ошибок задавать неоткуда — контроллера у них нет,
     * поэтому они прописаны в самом шаблоне и держатся на том, что композер
     * layouts.main видит переменные дочернего шаблона.
     */
    public function test_missing_page_has_own_meta(): void
    {
        $content = $this->get('/no-such-page')->getContent();

        preg_match('/<meta name="description" content="([^"]*)"/', $content, $description);
        preg_match('/<title>([^<]*)</', $content, $title);

        $this->assertStringContainsString('Такой страницы на сайте нет', $description[1] ?? '');
        $this->assertStringContainsString('Страница не найдена', $title[1] ?? '');
        $this->assertStringContainsString('name="robots" content="noindex, follow"', $content);
    }
}

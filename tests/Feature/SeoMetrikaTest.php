<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Внешняя видимость: счётчик Яндекс.Метрики и meta-теги подтверждения прав
 * для поисковиков. Аккаунтов у проекта пока нет, поэтому всё включается
 * только конфигом — без значений на страницах не должно быть ни тегов, ни
 * скрипта.
 */
class SeoMetrikaTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrika_is_not_rendered_without_counter_id(): void
    {
        config(['seo.yandex_metrika_id' => null]);

        $response = $this->get(route('main.index'));

        $response->assertSuccessful();
        $response->assertDontSee('mc.yandex.ru');
    }

    public function test_metrika_is_rendered_with_counter_id(): void
    {
        config(['seo.yandex_metrika_id' => '12345678']);

        $response = $this->get(route('main.index'));

        $response->assertSee('https://mc.yandex.ru/metrika/tag.js', false);
        $response->assertSee('ym(12345678, "init"', false);
        // noscript-пиксель для посетителей без JS — часть официального сниппета.
        $response->assertSee('https://mc.yandex.ru/watch/12345678', false);
        // Вебвизор не включаем сознательно (запись действий посетителя против
        // псевдонимизации post_views, плюс лишний вес) — регрессия вида
        // «кто-то дописал webvisor:true» ловится здесь.
        $response->assertDontSee('webvisor');
    }

    public function test_verification_meta_tags_are_not_rendered_without_codes(): void
    {
        config([
            'seo.yandex_verification' => null,
            'seo.google_site_verification' => null,
        ]);

        $response = $this->get(route('main.index'));

        $response->assertDontSee('yandex-verification');
        $response->assertDontSee('google-site-verification');
    }

    public function test_verification_meta_tags_are_rendered_with_codes(): void
    {
        config([
            'seo.yandex_verification' => 'yandex-code',
            'seo.google_site_verification' => 'google-code',
        ]);

        $response = $this->get(route('main.index'));

        $response->assertSee('<meta name="yandex-verification" content="yandex-code">', false);
        $response->assertSee('<meta name="google-site-verification" content="google-code">', false);
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Тесты не зависят от собранного фронта.
         *
         * layouts/app.blade.php (страницы авторизации) тянет @vite, а
         * public/build лежит в .gitignore — на машине разработчика манифест
         * остаётся от прошлых сборок и всё зелено, в чистом окружении та же
         * страница отдаёт 500. Именно так CI поймал восемь падений, которых
         * локально не было ни одного.
         *
         * Проверять наличие бандла — задача сборки, а не feature-теста, и в CI
         * для этого есть отдельный шаг.
         */
        $this->withoutVite();
    }

    /**
     * Настоящие байты PNG 1×1 для фикстур скачивания картинок.
     *
     * Произвольная строка вроде 'fake-image-bytes' больше не годится:
     * ContentImageService::storeImage() определяет расширение по содержимому
     * и отвергает всё, что не является растровой картинкой (регрессия на
     * RCE через расширение из чужого URL, аудит 2026-08-09).
     */
    protected function pngBytes(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }
}

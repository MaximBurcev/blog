<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

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

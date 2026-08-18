<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Фавиконка: файлы и разметка в <head>.
 *
 * Регрессия из Яндекс.Вебмастера: в public/ лежал favicon.ico на 0 байт, а в
 * разметке не было ни одного <link rel="icon"> — робот полгода отдавал «файл
 * favicon недоступен». Пустой файл при этом честно возвращал 200, так что
 * проверкой доступности такое не поймать: нужен размер.
 *
 * Вторая жалоба того же робота — «добавьте SVG или 120×120». Вектор условие
 * закрывает, но растровый кандидат крупнее 48×48 всё равно нужен: робот
 * вправе предпочесть растр.
 */
class FaviconTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function iconFiles(): array
    {
        return [
            'ico' => ['favicon.ico'],
            'svg' => ['favicon.svg'],
            'png 192' => ['icon-192.png'],
            'apple-touch' => ['apple-touch-icon.png'],
            'manifest' => ['site.webmanifest'],
        ];
    }

    #[DataProvider('iconFiles')]
    public function test_icon_file_exists_and_is_not_a_stub(string $file): void
    {
        $path = public_path($file);

        $this->assertFileExists($path);
        // Именно размер, а не только существование: тот самый favicon.ico был
        // нулевой длины и при этом честно отдавался с кодом 200. Порог низкий
        // намеренно — ловим заглушку, а не «маленький файл»: валидный
        // site.webmanifest весит 459 байт.
        $this->assertGreaterThan(100, filesize($path), "{$file} подозрительно мал — не заглушка ли это");
    }

    /**
     * Ради этого размера правка и делалась: в favicon.ico максимум 48×48, а
     * робот просит 120×120. Без проверки самого файла тест остался бы зелёным
     * после перегенерации иконки в меньшем размере — и жалоба вернулась бы.
     */
    public function test_raster_icon_is_large_enough_for_the_crawler(): void
    {
        [$width, $height] = getimagesize(public_path('icon-192.png'));

        $this->assertSame(192, $width);
        $this->assertSame(192, $height);
        $this->assertGreaterThanOrEqual(120, min($width, $height));
    }

    #[DataProvider('layoutRoutes')]
    public function test_head_links_every_icon_variant(string $route): void
    {
        $html = $this->get($route)->assertOk()->getContent();

        // Проверяем пару «rel + href», а не тег целиком: полный тег с жёстким
        // порядком атрибутов ломался бы от добавления type, а проверка одного
        // href не заметила бы подмены rel.
        foreach ([
            'icon' => 'favicon.ico',
            'manifest' => 'site.webmanifest',
        ] as $rel => $file) {
            $this->assertMatchesRegularExpression(
                '~<link[^>]*rel="'.$rel.'"[^>]*href="'.preg_quote(asset($file), '~').'"~',
                $html,
                "нет <link rel=\"{$rel}\"> на {$file}",
            );
        }

        $this->assertMatchesRegularExpression(
            '~<link[^>]*rel="icon"[^>]*type="image/svg\+xml"[^>]*href="'.preg_quote(asset('favicon.svg'), '~').'"~',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~<link[^>]*rel="icon"[^>]*sizes="192x192"[^>]*href="'.preg_quote(asset('icon-192.png'), '~').'"~',
            $html,
        );
    }

    /**
     * Партиал подключён в двух макетах, и страницы авторизации живут на втором.
     *
     * @return array<string, array{string}>
     */
    public static function layoutRoutes(): array
    {
        return [
            'layouts.main — публичная часть' => ['/'],
            'layouts.app — авторизация' => ['/login'],
        ];
    }

    /**
     * SVG обязан идти последним среди rel="icon".
     *
     * Связка «ico с sizes=any + svg» — приём, которым браузеру с поддержкой
     * вектора подсовывают вектор. Кандидат с конкретным sizes, поставленный
     * после SVG, перетягивает выбор на себя, и на HiDPI-экранах вместо 1.2 КБ
     * вектора поедет 5 КБ растра.
     */
    public function test_svg_is_the_last_icon_candidate(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('~<link[^>]*rel="icon"[^>]*>~', $html, $matches);

        $this->assertNotEmpty($matches[0]);
        $this->assertStringContainsString(
            'image/svg+xml',
            end($matches[0]),
            'после SVG появился другой rel="icon" — браузер выберет растр вместо вектора',
        );
    }
}

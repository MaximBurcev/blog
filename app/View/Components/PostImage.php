<?php

namespace App\View\Components;

use App\Service\ImageVariantService;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * Превью поста с srcset по уменьшенным вариантам (см. ImageVariantService).
 *
 * Раньше во всех листингах стоял голый <img src> с оригиналом на 1200 px,
 * хотя карточка занимает 370 px, а сайдбар — 80. Компонент собран, чтобы
 * набор ширин и правила sizes лежали в одном месте, а не расползались по
 * пяти шаблонам.
 */
class PostImage extends Component
{
    public ?string $srcset;

    public string $src;

    public function __construct(
        ImageVariantService $variants,
        public ?string $path = null,
        public string $alt = '',
        public ?int $width = null,
        public ?int $height = null,
        /**
         * Ширина картинки в вёрстке — по ней браузер выбирает вариант.
         * Значение по умолчанию описывает карточку листинга: во всю ширину
         * на телефоне, половина на планшете, 370 px на десктопе.
         */
        public string $sizes = '(max-width: 575px) calc(100vw - 30px), (max-width: 767px) 510px, (max-width: 991px) 150px, 225px',
        public string $loading = 'lazy',
        public ?string $fetchpriority = null,
    ) {
        // У постов без обложки общая заглушка, и ей варианты нужны не
        // меньше: сам файл — 1920x960 на 61 КБ, а стоит он в тех же
        // карточках по 223 px. В og:image при этом уходит оригинал.
        $path ??= $this->defaultImagePath();

        $this->src = asset('storage/'.$path);
        $this->srcset = $variants->srcset($path);
    }

    /**
     * config('seo.default_image') хранит путь вместе с префиксом «storage/»
     * — он же используется как готовый URL для og:image. Здесь нужен путь
     * внутри диска, без префикса.
     */
    private function defaultImagePath(): string
    {
        return (string) Str::after(config('seo.default_image'), 'storage/');
    }

    public function render()
    {
        return view('components.post-image');
    }
}

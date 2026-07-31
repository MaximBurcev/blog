<?php

namespace App\View\Components;

use App\Service\ImageVariantService;
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
        public string $sizes = '(max-width: 575px) 100vw, (max-width: 991px) 50vw, 370px',
        public string $loading = 'lazy',
    ) {
        // Постов без обложки хватает — им отдаём общую заглушку, для неё
        // вариантов нет и srcset не нужен.
        $this->src = $path ? asset('storage/'.$path) : asset(config('seo.default_image'));
        $this->srcset = $path ? $variants->srcset($path) : null;
    }

    public function render()
    {
        return view('components.post-image');
    }
}

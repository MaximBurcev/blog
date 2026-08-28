<?php

return [
    'default_title' => env('APP_NAME', 'Laravel'),

    'default_description' => 'Блог о разработке: новости, статьи и переводы материалов',

    'default_image' => 'storage/images/laravel.jpg',

    // og:locale — фиксированное значение, а не app()->getLocale(): формат тут
    // не «ru», а «ru_RU», и от переключения локали интерфейса он не зависит.
    'og_locale' => 'ru_RU',

    // Счётчик Яндекс.Метрики. Пусто — сниппет не выводится вовсе.
    'yandex_metrika_id' => env('YANDEX_METRIKA_ID'),

    // Коды подтверждения прав на сайт для поисковиков (meta-теги в шапке).
    // Пусто — тег не выводится.
    'yandex_verification' => env('YANDEX_VERIFICATION'),
    'google_site_verification' => env('GOOGLE_SITE_VERIFICATION'),
];

<?php

return [
    'default_title' => env('APP_NAME', 'Laravel'),

    'default_description' => 'Блог о разработке: новости, статьи и переводы материалов',

    'default_image' => 'storage/images/laravel.jpg',

    // og:locale — фиксированное значение, а не app()->getLocale(): формат тут
    // не «ru», а «ru_RU», и от переключения локали интерфейса он не зависит.
    'og_locale' => 'ru_RU',
];

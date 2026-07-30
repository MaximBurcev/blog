<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HSTS (Strict-Transport-Security)
    |--------------------------------------------------------------------------
    |
    | Заголовок выставляется ТОЛЬКО на HTTPS-ответах: на http он игнорируется
    | браузером, а на локальной разработке по http его отправка бессмысленна.
    |
    | include_subdomains по умолчанию выключен — включать только когда точно
    | известно, что все поддомены обслуживаются по HTTPS: браузер запомнит
    | политику на max_age секунд, и поддомен без сертификата станет
    | недоступен. preload (внесение в список браузеров) откатывается ещё
    | сложнее, поэтому тоже opt-in.
    |
    */

    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', true),
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),
        'preload' => (bool) env('HSTS_PRELOAD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Политика собирается в App\Http\Middleware\SecurityHeaders — там же
    | описано, что она даёт, а что нет (инлайн-скрипты в шаблонах AdminLTE,
    | Livewire и Alpine требуют 'unsafe-inline'/'unsafe-eval').
    |
    | report_only переключает заголовок на Content-Security-Policy-Report-Only:
    | нарушения только логируются браузером, ничего не блокируется. Режим для
    | обкатки ужесточённой политики перед включением.
    |
    | extra_hosts — источники, которые нужно разрешить без правки кода
    | (подключили новую CDN, счётчик, внешний виджет). Список через запятую,
    | добавляется в script/style/font/img/connect.
    |
    */

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),
        'report_uri' => env('CSP_REPORT_URI'),
        'extra_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CSP_EXTRA_HOSTS', ''))
        ))),
    ],

];

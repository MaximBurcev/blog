<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Release Parser Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the release URL parser and link extraction.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | CSS Selector for Link Extraction
    |--------------------------------------------------------------------------
    |
    | The CSS selector used to find links in the HTML content.
    | Default: 'td.bodyContent a[href'
    |
    */
    'parser_selector' => env('RELEASE_PARSER_SELECTOR', 'td.bodyContent a[href]'),

    /*
    |--------------------------------------------------------------------------
    | Per-Domain CSS Selectors for Link Extraction
    |--------------------------------------------------------------------------
    |
    | 'parser_selector' выше — это глобальный фолбэк, заточенный под
    | табличную email-вёрстку mailer.inovica.com. Другие рассылки/дайджесты
    | (JavaScript Weekly и т.п.) вёрстаны иначе — здесь можно переопределить
    | селектор ссылок по домену страницы-релиза.
    |
    */
    'parser_selectors_by_domain' => [
        // ':first-of-type' — в каждом пункте дайджеста берём только первую
        // (заголовочную) ссылку; остальные — вторичные упоминания в тексте
        // пункта (сайт проекта, автор, доп. ресурсы), их парсить не нужно.
        'javascriptweekly.com' => 'li a:first-of-type',
    ],

    /*
    |--------------------------------------------------------------------------
    | SOCKS5 Proxy for Parser and Translator
    |--------------------------------------------------------------------------
    |
    | Proxy in host:port format used by StorePostJob (curl fetch) and
    | Google Translate requests. Read via config() so it keeps working
    | after `artisan config:cache` (bare env() would return null).
    |
    */
    'curl_proxy' => env('CURL_PROXY'),

    /*
    |--------------------------------------------------------------------------
    | Curl Binary Override (curl-impersonate)
    |--------------------------------------------------------------------------
    |
    | Path to a curl-impersonate wrapper (e.g. curl_chrome116) used by
    | StorePostJob to bypass TLS-fingerprint bot detection (Cloudflare on
    | medium.com etc.). The wrapper sets Chrome TLS fingerprint and headers
    | itself. Leave empty to use system /usr/bin/curl with manual headers.
    |
    */
    'curl_binary' => env('CURL_BINARY'),

    /*
    |--------------------------------------------------------------------------
    | CSS Class for Post Content Extraction
    |--------------------------------------------------------------------------
    |
    | The CSS class name used by StorePostJob to find the content block
    | inside each parsed article page.
    |
    */
    'post_selector' => env('RELEASE_POST_SELECTOR', 'article-body'),

    /*
    |--------------------------------------------------------------------------
    | Per-Domain CSS Selectors for Post Content
    |--------------------------------------------------------------------------
    |
    | Maps domain substrings to CSS class names used by StorePostJob
    | to find the content block. If a URL matches a domain key,
    | that selector is used; otherwise 'post_selector' is used as fallback.
    |
    | ВНИМАНИЕ: основное место для новых правил — админка
    | (Настройки → Селекторы сайтов, таблица site_selectors); её значения
    | имеют приоритет. Этот список остался фолбэком для доменов, которых в
    | таблице нет, и источником данных для первичного наполнения — см.
    | ContentSelectorResolver и миграцию create_site_selectors_table.
    |
    */
    'domain_selectors' => [
        'dev.to' => '#article-body',
        'medium.com' => 'article',
        'gitconnected.com' => 'article',
        // article берёт весь каркас страницы вместе с навигацией сайта и
        // сайдбаром: 60 ссылок и 6029 символов против 26 и 5145 у самого
        // текста. Тело статьи лежит в div.article-content.
        'laravel-news.com' => 'article-content',
        'symfony.com' => 'article',
        'langchain.com' => '.w-richtext',
        'stackademic.com' => 'article',
        'stitcher.io' => 'article',
        'christoph-rumpel.com' => 'article',
        'cakedc.com' => 'article',
        'thephp.foundation' => '.leading-8',
        // Блог JetBrains (PhpStorm/WebStorm и т.д.): тело статьи лежит в
        // div.content.js-toc-content внутри section.article-section. Голый
        // класс 'content' брать нельзя — он же у блока комментариев ниже,
        // поэтому цепляемся за js-toc-content. Хвост этого блока (теги,
        // кнопки шаринга, пагинация, форма подписки) и шапку автора
        // вырезает StorePostJob::stripJetBrainsChrome().
        'blog.jetbrains.com' => '.js-toc-content',
        // Личный блог на Tailwind: семантического <article> на странице нет,
        // единственный контейнер текста статьи — div.post-content.
        'wendelladriel.com' => '.post-content',
        // WordPress: тело записи в div.entry-content внутри <article>. Сам
        // <article> брать нельзя — в него входит шапка с датой/категориями.
        'exakat.io' => '.entry-content',
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Links to Process
    |--------------------------------------------------------------------------
    |
    | Maximum number of links to extract and process from a single page.
    | Default: 5
    |
    */
    'max_links' => env('RELEASE_MAX_LINKS', 20),

    /*
    |--------------------------------------------------------------------------
    | Link Offset
    |--------------------------------------------------------------------------
    |
    | Number of links to skip before starting extraction.
    | Default: 2
    |
    */
    'offset' => env('RELEASE_OFFSET', 0),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for HTTP requests when fetching content.
    | Default: 30
    |
    */
    'timeout' => env('RELEASE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | User Agent
    |--------------------------------------------------------------------------
    |
    | User agent string to use when making HTTP requests.
    |
    */
    'user_agent' => env('RELEASE_USER_AGENT', 'Mozilla/5.0 (compatible; ReleaseParser/1.0)'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Downloaded Content Size (bytes)
    |--------------------------------------------------------------------------
    |
    | Ни --max-time/timeout, ни сам факт allowed_domains не ограничивают
    | ОБЪЁМ ответа — внешняя страница/картинка может отдавать гигабайты или
    | бесконечный поток, что кладёт воркер queue:work по памяти или диску.
    | Используется StorePostJob (curl --max-filesize), ContentImageService
    | и ReleaseService (Guzzle on_headers, обрывает по Content-Length).
    | Default: 20 MB.
    |
    */
    'max_content_length' => (int) env('RELEASE_MAX_CONTENT_LENGTH', 20 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | HTML Import Directory
    |--------------------------------------------------------------------------
    |
    | Каталог, из которого разрешено читать локальные HTML-файлы (опция
    | --html-file у post:parse). StorePostJob раньше отдавал произвольный путь
    | в file_get_contents без ограничений: содержимое файла становится телом
    | поста, то есть `--html-file /var/www/html/.env` опубликовало бы секреты
    | (security-audit 2026-08-01, INF-1).
    |
    | Из веба путь задать нельзя — только из CLI, поэтому находка не Critical;
    | но джоба принимает html_file из payload очереди, и цена ограничения —
    | четыре строки.
    |
    */
    'html_import_dir' => env('RELEASE_HTML_IMPORT_DIR', storage_path('app/imports')),

    /*
    |--------------------------------------------------------------------------
    | Challenge Solver (FlareSolverr)
    |--------------------------------------------------------------------------
    |
    | Адрес FlareSolverr — сервиса, который открывает страницу в headless-
    | браузере и потому проходит antibot-проверки с исполнением JS. Пустое
    | значение = выключено, и тогда пайплайн работает как прежде.
    |
    | Нужен для medium.com: Cloudflare managed challenge для curl недостижим,
    | а обходной путь через RSS покрывает только последние десять публикаций
    | автора. Пробуется ПОСЛЕ RSS — он на порядок дешевле (один запрос против
    | браузерной сессии на ~20 секунд).
    |
    | Запуск: docker run -d --name flaresolverr -p 127.0.0.1:8191:8191 \
    |   --memory=512m --restart=unless-stopped ghcr.io/flaresolverr/flaresolverr
    | Замеры: 123 МБ в покое, ~145 МБ под запросом, образ 717 МБ.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | News Section Heading
    |--------------------------------------------------------------------------
    |
    | Заголовок секции дайджеста, из которой импортируются новости. У соседних
    | секций («Tutorials and Talks», «Interesting Projects, Tools and Libraries»)
    | структура ровно та же, так что импорт переиспользуется сменой значения.
    |
    */
    'news_section_heading' => env('RELEASE_NEWS_SECTION', 'News and Announcements'),

    'challenge_solver_url' => env('FLARESOLVERR_URL'),

    'challenge_solver_timeout' => (int) env('FLARESOLVERR_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Enable Job Dispatch
    |--------------------------------------------------------------------------
    |
    | Whether to automatically dispatch jobs for extracted links.
    | Set to false to disable automatic job creation.
    |
    */
    'enable_job_dispatch' => env('RELEASE_ENABLE_JOB_DISPATCH', true),

    /*
    |--------------------------------------------------------------------------
    | Allowed Domains
    |--------------------------------------------------------------------------
    |
    | Array of allowed domains for URL extraction.
    | Leave empty to allow all domains.
    |
    */
    'allowed_domains' => env('RELEASE_ALLOWED_DOMAINS')
        ? explode(',', env('RELEASE_ALLOWED_DOMAINS'))
        : [],

    /*
    |--------------------------------------------------------------------------
    | Blocked Domains
    |--------------------------------------------------------------------------
    |
    | Array of blocked domains to exclude from URL extraction.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Skipped Sources (не статьи)
    |--------------------------------------------------------------------------
    |
    | Домены, ссылки на которые в дайджесте попадаются регулярно, но статьями
    | не являются: видео, репозитории, справочники, страницы подкастов.
    | Парсер честно скачивает их, не находит контент по селектору и создаёт
    | пост-заглушку — 7 из 13 таких заглушек в базе на 2026-08-09 пришли
    | именно отсюда (youtube 2, github, php.net, podcast.laravel-news.com,
    | blog.stanleymasinde.com, highlit.co).
    |
    | Отличие от blocked_domains: те проверяет UrlSafetyChecker уже на этапе
    | фетча, и ссылка всё равно превращается в заглушку с ошибкой. Эти
    | отсеиваются раньше — до постановки джобы, поэтому поста не возникает.
    |
    | Совпадение по границе метки (HostMatcher): 'youtube.com' покрывает
    | 'www.youtube.com', но не 'youtube.com.evil.tld'.
    |
    */
    'skipped_domains' => env('RELEASE_SKIPPED_DOMAINS')
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('RELEASE_SKIPPED_DOMAINS')))))
        : [
            'youtube.com',
            'youtu.be',
            'github.com',
            'php.net',
            'packagist.org',
            'twitter.com',
            'x.com',
            'podcast.laravel-news.com',
        ],

    'blocked_domains' => env('RELEASE_BLOCKED_DOMAINS')
        ? explode(',', env('RELEASE_BLOCKED_DOMAINS'))
        : [
            'facebook.com',
            'twitter.com',
            'instagram.com',
            'linkedin.com',
        ],

    /*
    |--------------------------------------------------------------------------
    | Section Headings Filter
    |--------------------------------------------------------------------------
    |
    | When set, only links from td.bodyContent blocks containing an h2
    | with matching text will be extracted.
    | Example: RELEASE_SECTION_HEADINGS="Articles,Tutorials and Talks"
    | Leave empty to extract from all td.bodyContent blocks.
    |
    */
    'section_headings' => env('RELEASE_SECTION_HEADINGS')
        ? explode(',', env('RELEASE_SECTION_HEADINGS'))
        : [],
];

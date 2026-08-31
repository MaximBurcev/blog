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
        // Выпуск JavaScript Weekly состоит из блоков двух видов, и ссылки в
        // них размечены по-разному:
        //
        //   1. Заголовочный материал — <table class="el-item"> с ссылкой в
        //      <span class="mainlink">. Это главные статьи выпуска.
        //   2. Компактные секции («In Brief», «Releases», «Articles and
        //      Videos») — <table class="content el-md">, где ссылка лежит
        //      прямо в <p>, иногда внутри <li>.
        //
        // Прежний селектор 'li a:first-of-type' видел только вторую половину
        // второго вида: на выпуске 799 он давал 18 ссылок против 35 и не
        // содержал НИ ОДНОГО из 13 заголовочных материалов — то есть ровно
        // тех статей, ради которых дайджест и разбирается.
        //
        // ':first-of-type' у el-md остаётся: там первая ссылка абзаца —
        // заголовочная, остальные вторичные упоминания в тексте (сайт
        // проекта, доп. ресурсы). Плата за это — секция «Releases»,
        // где несколько релизов перечислены через запятую в одном <p>:
        // берётся только первый. Это осознанный размен, релизные заметки
        // для блога наименее ценны, а без ':first-of-type' в очередь
        // добавляется восемь вторичных ссылок на выпуск.
        'javascriptweekly.com' => 'span.mainlink a, table.el-md p a:first-of-type',
    ],

    /*
    |--------------------------------------------------------------------------
    | Рекламные блоки дайджеста
    |--------------------------------------------------------------------------
    |
    | Спонсорский материал в выпуске свёрстан ровно как обычный: та же
    | <table class="el-item">, та же ссылка в <span class="mainlink">.
    | Отличает его только метка <span class="tag-sponsor">sponsor</span>
    | внутри того же блока: классы у рекламного и обычного пункта совпадают,
    | а подняться от ссылки к предку CSS-селектор не умеет (':has()' в
    | Symfony CssSelector не поддерживается) — потому правило и состоит из
    | пары «контейнер пункта + метка внутри него».
    |
    | Отсюда пара «контейнер пункта + метка»: ссылка отбрасывается, если в
    | ближайшем контейнере есть метка. Домены спонсоров каждую неделю разные
    | (developers.webflow.com, jobs.fidelity.com, …), так что skipped_domains
    | для них бесполезен — отсюда разметка, а не список доменов.
    |
    */
    'parser_sponsor_markers_by_domain' => [
        'javascriptweekly.com' => ['item' => 'table.el-item', 'marker' => '.tag-sponsor'],
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
    | OCR Binary (Tesseract)
    |--------------------------------------------------------------------------
    |
    | Чем DiagramTranslatorService распознаёт текст на картинках. Это системный
    | пакет (tesseract-ocr + tesseract-ocr-eng), а не зависимость composer:
    | локально его ставит docker/8.4/Dockerfile, на сервере — задача
    | `./vendor/bin/envoy run ocr-install`. Без него перевод текста на
    | картинках не работает, и до 17.08.2026 это происходило молча.
    |
    | Вынесено в конфиг ради теста: подменив значение на несуществующий файл,
    | можно проверить, что отсутствие бинаря логируется как проблема, а не
    | выдаётся за «на картинке нет текста».
    |
    */
    'ocr_binary' => env('OCR_BINARY', 'tesseract'),

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
        // На странице два <article>: первый — тело статьи (голый тег даёт
        // XPath (//article)[1], то есть ровно его), второй — форма подписки
        // на рассылку. Класс-селектор не подошёл бы: совпавшие узлы
        // склеиваются (SelectorXPathBuilder) и форма попала бы в пост.
        'yellowraincoat.co.uk' => 'article',

        // Источники JavaScript Weekly. Замерено 26.08.2026 на выпуске 799,
        // в скобках — РАЗМЕР HTML выбранного блока и во что он обходится:
        // число запросов к модели на одно тело статьи (translation.gemini.
        // max_chunk_chars = 20 000). Мерить надо именно HTML, а не текст:
        // GeminiTranslator режет то, что отдаёт StorePostJob, а отдаёт он
        // saveHTML() узлов — у tkdodo.eu текста 44 КБ, а разметки 536 КБ.
        //
        // У класса склеиваются ВСЕ совпавшие узлы (см. SelectorXPathBuilder),
        // поэтому предпочтение селекторам, дающим ровно один блок.
        'hacks.mozilla.org' => 'article',       //   6 КБ / 1 запрос
        'svelte.dev' => 'article',              //  12 КБ / 1
        'carlos-menezes.com' => 'article',      //  12 КБ / 1
        'daverupert.com' => 'article',          //  14 КБ / 1
        'infrequently.org' => 'article',        //  18 КБ / 1
        'ionic.io' => '.single-content',        //  23 КБ / 2
        'blog.master.dev' => 'article',         //  24 КБ / 2
        'pnpm.io' => '.markdown',               //  38 КБ / 2 (Docusaurus)
        'gtkx.dev' => '.content-container',     //  44 КБ / 3 (VitePress)
        'bhugo.dev' => '.prose',                //  46 КБ / 3
        'nodejs.org' => 'main',                 //  70 КБ / 4 — классы вёрстки
        // хешируются сборкой (layouts-module__mzYk8q__…) и меняются с каждым
        // релизом сайта, поэтому цепляемся за тег.
        'blog.gaborkoos.com' => 'article',      //  87 КБ / 5
        // Дальше — дорогие. Одна такая статья забирает треть-половину
        // суточной бесплатной квоты цепочки моделей (60 запросов), а
        // budget_seconds = 240 оборвёт перевод раньше, чем кончатся куски:
        // хвост уедет на скрейпер с отметкой «перевод неполный». Правило
        // заведено осознанно — заглушка вместо статьи хуже, — но выпуск,
        // где таких материалов несколько, съест квоту целиком.
        'runjs.app' => 'article',               // 391 КБ / 20
        'tkdodo.eu' => 'article',               // 536 КБ / 27
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

    /*
    |--------------------------------------------------------------------------
    | Сколько ПОВТОРОВ даётся одной ссылке новостного импорта
    |--------------------------------------------------------------------------
    |
    | Именно повторов, а не разборов всего: первый заход у новой ссылки поста
    | ещё нет, считать инкремент некуда, поэтому при лимите 3 внешних скачиваний
    | будет 4 — первое и три повтора.
    |
    | Заглушки (parse_status = failed) импорт перезапускает намеренно: сбой мог
    | быть временным и ссылка не должна пропасть из-за одной неудачи. Но часть
    | ссылок новостной секции — ролики YouTube, главная php.net, страницы
    | релизов GitHub, — где статьи не существует, и без потолка «второй шанс»
    | превращался в вечный цикл: каждое утро заново скачать, заново не найти
    | тело статьи, заново сохранить заглушку. За сутки это съедало больше
    | половины бесплатной квоты модели.
    |
    | Три — это «дайте временной беде пройти»: антибот и упавший источник
    | укладываются в пару дней, а неразбираемая страница не починится и за сто.
    |
    | 0 — снять ограничение (поведение до появления счётчика).
    |
    */

    'news_retry_limit' => (int) env('RELEASE_NEWS_RETRY_LIMIT', 3),

    /*
    |--------------------------------------------------------------------------
    | Tools Section
    |--------------------------------------------------------------------------
    |
    | Секция дайджеста с утилитами и библиотеками — источник раздела /tools.
    | Порог длины описания ниже новостного: у пакета описание в одну строку.
    |
    */
    'tools_section_heading' => env('RELEASE_TOOLS_SECTION', 'Interesting Projects, Tools and Libraries'),

    'tools_min_summary_length' => (int) env('RELEASE_TOOLS_MIN_SUMMARY', 20),

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
            // Обсуждение, а не статья: тела по селектору там нет, плюс на
            // curl из дайджеста приходит 403 (проверено 26.08.2026 на ссылке
            // с AMA команды Next.js в выпуске JavaScript Weekly 799).
            'reddit.com',
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

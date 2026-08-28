<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    @if(auth()->check())
        <meta name="user-id" content="{{ auth()->id() }}">
    @endif

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }}</title>

    @include('partials.favicon')

    <meta name="description" content="{{ $description }}">

    {{-- Каноническая ссылка склеивает дубли: сайт доступен и по http, и по
         https, и через /index.php, а листинги ещё и по ?page=N. --}}
    <link rel="canonical" href="{{ $canonical }}">
    @if($robots)
        <meta name="robots" content="{{ $robots }}">
    @endif
    <link rel="alternate" type="application/rss+xml" title="{{ config('app.name', 'Laravel') }}" href="{{ route('feed.index') }}">

    <meta property="og:site_name" content="{{ config('app.name', 'Laravel') }}">
    <meta property="og:locale" content="{{ config('seo.og_locale') }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $ogUrl }}">
    <meta property="og:image" content="{{ $ogImage }}">

    @if($articleMeta)
        <meta property="article:published_time" content="{{ $articleMeta['published_time'] }}">
        <meta property="article:modified_time" content="{{ $articleMeta['modified_time'] }}">
        @if($articleMeta['section'])
            <meta property="article:section" content="{{ $articleMeta['section'] }}">
        @endif
        @foreach($articleMeta['tags'] as $articleTag)
            <meta property="article:tag" content="{{ $articleTag }}">
        @endforeach
    @endif

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    {{-- summernote (редактор из админки) и flag-icon отсюда убраны: на
         публичных страницах они не используются, но блокировали рендер.
         Font Awesome убран следом: единственной иконкой было сердечко лайка,
         теперь это инлайновый SVG в шаблоне поста. --}}
    <link rel="preload" href="{{ asset('assets/fonts/dm-sans/dm-sans-latin-variable.woff2') }}" as="font" type="font/woff2" crossorigin>
    {{-- Стили AOS вшиты в style.css: отдельный запрос на 2.6 КБ стоил
         160 мс блокировки отрисовки. --}}
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <style>
        .blog .blog-post .blog-post-thumbnail-wrapper {
            background-color: #1a1a2e;
        }
        .blog .blog-post .blog-post-thumbnail-wrapper img {
            object-fit: contain;
            object-position: center center;
        }

        /* Хлебные крошки и ссылки на категорию/теги — новые блоки, в теме
           Edica их стилей нет. */
        .edica-breadcrumbs {
            padding-top: 1.5rem;
            font-size: 0.875rem;
            color: #6c757d;
        }

        .edica-breadcrumbs a {
            color: #6c757d;
        }

        .edica-breadcrumbs span {
            margin: 0 0.35rem;
        }

        .post-taxonomy {
            margin-top: 1.5rem;
            font-size: 0.95rem;
        }

        .post-taxonomy-label {
            margin-right: 0.35rem;
            color: #6c757d;
        }

        .post-taxonomy a {
            margin-right: 0.35rem;
        }
    </style>
    {{-- Тема подсветки кода уехала в @stack('head') шаблона поста: на всех
         остальных страницах подсвечивать нечего. --}}
    @stack('head')

    {{-- Общая для всех страниц разметка: сам сайт и его издатель. Разметка
         конкретной страницы (BlogPosting, BreadcrumbList) приезжает из
         шаблонов через @stack('schema'). --}}
    @include('partials.json-ld', ['data' => [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'WebSite',
                '@id' => url('/').'#website',
                'url' => url('/'),
                'name' => config('app.name'),
                'description' => config('seo.default_description'),
                'inLanguage' => 'ru-RU',
                'publisher' => ['@id' => url('/').'#organization'],
                // SearchAction здесь был и удалён: он вёл на /search?q=…,
                // который Disallow в robots.txt, — противоречивые сигналы
                // поисковику. Тем более Google отключил sitelinks searchbox,
                // единственное, что эта разметка давала.
            ],
            [
                '@type' => 'Organization',
                '@id' => url('/').'#organization',
                'name' => config('app.name'),
                'url' => url('/'),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => asset(config('seo.default_image')),
                ],
            ],
        ],
    ]])

    @stack('schema')

</head>
<body>
{{-- Прелоадер убран: белый оверлей на весь экран (z-index 9999) висел до
     window.load, то есть до загрузки последней картинки и стороннего скрипта.
     Пользователь и Lighthouse всё это время видели пустой белый экран, хотя
     контент уже был отрисован. --}}
<header class="edica-header">
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-light">

            <button class="navbar-toggler d-lg-none" type="button" data-toggle="collapse" data-target="#edicaMainNav"
                    aria-controls="collapsibleNavId" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="edicaMainNav">
                <ul class="navbar-nav mx-auto mt-2 mt-lg-0">
                    <li class="nav-item active">
                        <a class="nav-link" href="{{ route('main.index') }}">Главная</a>
                    </li>
                    <li>
                        <a class="nav-link" href="{{ route('news.index') }}">Новости</a>
                    </li>
                    <li>
                        <a class="nav-link" href="{{ route('tools.index') }}">Инструменты</a>
                    </li>
                    <li>
                        <a class="nav-link" href="{{ route('main.search') }}">Поиск</a>
                    </li>
                </ul>

            </div>
        </nav>
    </div>
</header>

@yield('content')

<br><br>
<footer class="edica-footer" data-aos="fade-up">
    <div class="container">
        <div class="footer-bottom-content">
            <nav class="nav footer-bottom-nav">
                <a href="{{ route('sitemap.index') }}">Карта сайта</a>
            </nav>
            <p class="mb-0">© {{ date('Y') }} {{ config('app.name') }}. Все права защищены.</p>
        </div>
    </div>
</footer>
{{-- Из фронта убраны jQuery, popper и bootstrap.js: jQuery нужен был только
     лоадеру и main.js (тот вешал hover на .dropdown, которых в этой вёрстке
     нет), popper — дропдаунам и тултипам bootstrap.js, а из самого
     bootstrap.js использовалось одно раскрытие бургер-меню. Оно ниже на
     ванильном JS, CSS-классы те же (.collapse / .show из style.css). --}}
<script src="{{ asset('assets/vendors/aos/aos.js') }}" defer></script>

{{-- Инлайн-скрипт выполняется сразу, а defer-скрипты — перед DOMContentLoaded,
     поэтому AOS внутри обработчика уже определён. --}}
<script nonce="{{ $cspNonce ?? '' }}">
    document.addEventListener('DOMContentLoaded', function () {
        AOS.init({ duration: 1000 });

        document.querySelectorAll('[data-toggle="collapse"]').forEach(function (toggler) {
            toggler.addEventListener('click', function () {
                var target = document.querySelector(toggler.dataset.target);
                if (!target) {
                    return;
                }
                var opened = target.classList.toggle('show');
                toggler.setAttribute('aria-expanded', opened ? 'true' : 'false');
            });
        });
    });
</script>

@stack('scripts')

{{-- Echo/Pusher подписывается на приватный канал пользователя — гостю этот
     бандл не нужен вовсе. --}}
@auth
    @vite(['resources/js/app.js'])
@endauth

</body>

</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    @if(auth()->check())
        <meta name="user-id" content="{{ auth()->id() }}">
    @endif

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title?? '' }}</title>
    <link rel="stylesheet" href="{{ asset('assets/vendors/flag-icon-css/css/flag-icon.min.css')  }}">
    <link rel="stylesheet" href="{{ asset('plugins/summernote/summernote-bs4.min.css')  }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/font-awesome/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/aos/aos.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    <script src="{{ asset('assets/vendors/jquery/jquery.min.js') }}"></script>
    <script src="{{ asset('assets/js/loader.js') }}"></script>



    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.6.0/highlight.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlightjs-themes@1.0.0/androidstudio.css"/>

</head>
<body>
<div class="edica-loader"></div>
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
            <p class="mb-0">© {{ date('Y') }} . Все права защищены.</p>
        </div>
    </div>
</footer>
<script src="{{ asset('assets/vendors/popper.js/popper.min.js') }}"></script>
<script src="{{ asset('assets/vendors/bootstrap/dist/js/bootstrap.min.js') }}"></script>
<script src="{{ asset('assets/vendors/aos/aos.js') }}"></script>
<script src="{{ asset('assets/js/main.js') }}"></script>




<script>
    AOS.init({
        duration: 1000
    });

    $(document).ready(function () {

        const preTags = document.getElementsByTagName('pre');
        const size = preTags.length;
        for (let i = 0; i < size; i++) {
            preTags[i].innerHTML = '<code>' + preTags[i].innerHTML + '</code>'; // wrap content of pre tag in code tag
        }
        hljs.highlightAll(); // apply highlighting

    });


</script>

@vite(['resources/js/app.js'])

</body>

</html>

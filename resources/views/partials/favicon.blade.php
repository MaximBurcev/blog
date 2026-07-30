{{-- Фавиконка. Раньше в public/ лежал favicon.ico на 0 байт и ни одного
     <link rel="icon"> в разметке — Яндекс.Вебмастер ругался «файл favicon
     недоступен для робота». Набор общий для публичной части и авторизации. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<meta name="theme-color" content="#2E937A">

@php
    // Своего контроллера у страниц ошибок нет, поэтому метаданные задаются
    // прямо здесь: композер layouts.main читает переменные шаблона, а без них
    // 404 ушла бы с общим описанием сайта и заголовком без пояснения.
    $title = 'Страница не найдена';
    $description = 'Такой страницы на сайте нет: адрес набран с ошибкой или материал удалён. Воспользуйтесь поиском или начните с главной.';
    // Статуса 404 достаточно, чтобы страница не попала в индекс, но робот
    // сначала читает разметку — noindex снимает вопрос и для тех, кто пришёл
    // по битой внешней ссылке. follow: ссылки ниже ведут на живые разделы.
    $robots = 'noindex, follow';
@endphp

@extends('layouts.main')

@section('content')
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Страница не найдена</h1>

            <div class="row">
                <div class="col-md-8">
                    <p data-aos="fade-up">
                        Адрес набран с ошибкой, устарел или материал был удалён.
                        Ошибка 404.
                    </p>

                    <form action="{{ route('main.search') }}" data-aos="fade-up">
                        <div class="form-group">
                            <input type="text" class="form-control" id="q" name="q" placeholder="Что ищете?">
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-warning btn-lg">Найти</button>
                        </div>
                    </form>

                    <p data-aos="fade-up">
                        Или перейдите в один из разделов:
                    </p>
                    <ul data-aos="fade-up">
                        <li><a href="{{ route('main.index') }}">Главная</a></li>
                        <li><a href="{{ route('news.index') }}">Новости</a></li>
                        <li><a href="{{ route('category.index') }}">Категории</a></li>
                        <li><a href="{{ route('tag.index') }}">Теги</a></li>
                        <li><a href="{{ route('sitemap.index') }}">Карта сайта</a></li>
                    </ul>
                </div>
            </div>

            <br><br>
        </div>
    </main>
@endsection

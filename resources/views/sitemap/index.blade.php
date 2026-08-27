@extends('layouts.main')

@section('content')
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Карта сайта</h1>

            {{-- Заголовки закрыты условием: контроллер отдаёт только
                 опубликованные посты и только разделы с ними, а в блоге, где
                 всё лежит в черновиках, любой из трёх списков может оказаться
                 пустым — заголовок над пустым <ul> выглядит поломкой. --}}
            @if($posts->isNotEmpty())
                <h2>Посты</h2>
                <ul>
                    @foreach($posts as $post)
                        <li><a href="{{ $post->permalink() }}">{{ $post->title }}</a></li>
                    @endforeach
                </ul>
            @endif
            @if($categories->isNotEmpty())
                <h2>Категории</h2>
                <ul>
                    @foreach($categories as $category)
                        <li><a href="{{ route('category.show', $category->code) }}">{{ $category->title }}</a></li>
                    @endforeach
                </ul>
            @endif
            @if($hasTools)
                <h2>Инструменты</h2>
                <ul>
                    <li><a href="{{ route('tools.index') }}">Утилиты и библиотеки</a></li>
                </ul>
            @endif
            @if($tags->isNotEmpty())
                <h2>Теги</h2>
                <ul>
                    @foreach($tags as $tag)
                        <li><a href="{{ route('tag.show', $tag->code) }}">{{ $tag->title }}</a></li>
                    @endforeach
                </ul>
            @endif
            <br><br>
        </div>
    </main>
@endsection

@extends('layouts.main')

@section('content')
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Карта сайта</h1>

            <h2>Посты</h2>
            <ul>
                @foreach($posts as $post)
                    <li><a href="{{ route('post.show', $post->code) }}">{{ $post->title }}</a></li>
                @endforeach
            </ul>
            <h2>Категории</h2>
            <ul>
                @foreach($categories as $category)
                    <li><a href="{{ route('category.show', $category->code) }}">{{ $category->title }}</a></li>
                @endforeach
            </ul>
            <h2>Теги</h2>
            <ul>
                @foreach($tags as $tag)
                    <li><a href="{{ route('tag.show', $tag->code) }}">{{ $tag->title }}</a></li>
                @endforeach
            </ul>
            <br><br>
        </div>
    </main>
@endsection

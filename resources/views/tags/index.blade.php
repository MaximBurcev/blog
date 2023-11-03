@extends('layouts.main')

@section('content')
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Теги</h1>
            <section class="featured-posts-section">
                <ul>
                    @foreach($tags as $tag)
                        <li><a href="{{ route('tag.show', $tag->code) }}">{{ $tag->title }}</a></li>
                    @endforeach
                </ul>
            </section>
            <br>
        </div>
    </main>
@endsection

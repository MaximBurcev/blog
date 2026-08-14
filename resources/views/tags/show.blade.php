@extends('layouts.main')

@push('schema')
    @include('partials.json-ld', ['data' => [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Теги', 'item' => route('tag.index')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $tag->title],
        ],
    ]])
@endpush

@section('content')
    <main class="blog">
        <div class="container">
            <nav aria-label="Хлебные крошки" class="edica-breadcrumbs">
                <a href="{{ route('main.index') }}">Главная</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('tag.index') }}">Теги</a>
            </nav>
            <h1 class="edica-page-title" data-aos="fade-up">Посты с тегом {{ $tag->title }}</h1>
            <section class="featured-posts-section">
                <div class="row">
                    @foreach($posts as $post)
                        <div class="col-md-4 fetured-post blog-post" data-aos="fade-up">
                            <div class="blog-post-thumbnail-wrapper">
                                <x-post-image :path="$post->preview_image" :alt="$post->title"
                                              :width="370" :height="240"/>
                            </div>
                            @if($post->category)
                                <p class="blog-post-category">
                                    <a href="{{ route('category.show', $post->category->code) }}">{{ $post->category->title }}</a>
                                </p>
                            @endif
                            <a href="{{ $post->permalink() }}" class="blog-post-permalink">
                                <h2 class="blog-post-title">{{ $post->title }}</h2>
                            </a>
                        </div>
                    @endforeach
                </div>
                <div class="row">
                    <div class="mx-auto">
                        {{ $posts->links() }}
                    </div>
                </div>
            </section>
            <br>
        </div>
    </main>
@endsection

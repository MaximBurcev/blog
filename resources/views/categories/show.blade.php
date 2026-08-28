@extends('layouts.main')

@push('schema')
    @include('partials.json-ld', ['data' => [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                // Листинг как подборка ссылок: позиции сквозные через
                // страницы пагинации, чтобы вторая страница не заявляла
                // тот же список с позиции 1 заново.
                '@type' => 'CollectionPage',
                '@id' => $canonical.'#collection',
                'url' => $canonical,
                'name' => $title,
                'isPartOf' => ['@id' => url('/').'#website'],
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListElement' => $posts->map(fn ($post, $i) => [
                        '@type' => 'ListItem',
                        'position' => ($posts->firstItem() ?? 1) + $i,
                        'url' => $post->permalink(),
                    ])->all(),
                ],
            ],
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => url('/')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Категории', 'item' => route('category.index')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $category->title],
                ],
            ],
        ],
    ]])
@endpush

@section('content')
<main class="blog">
    <div class="container">
        <nav aria-label="Хлебные крошки" class="edica-breadcrumbs">
            <a href="{{ route('main.index') }}">Главная</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('category.index') }}">Категории</a>
        </nav>
        <h1 class="edica-page-title" data-aos="fade-up">Посты категории {{ $category->title }}</h1>
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

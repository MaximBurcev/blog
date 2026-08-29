@extends('layouts.main')

@push('schema')
    @include('partials.json-ld', ['data' => [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                // См. categories/show: листинг-CollectionPage с позициями
                // сквозь пагинацию.
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
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Теги', 'item' => route('tag.index')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $tag->title],
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
                <a href="{{ route('tag.index') }}">Теги</a>
            </nav>
            <h1 class="edica-page-title" data-aos="fade-up">Посты с тегом {{ $tag->title }}</h1>
            <section class="featured-posts-section">
                <div class="row">
                    @foreach($posts as $post)
                        @include('partials.post-card', ['post' => $post])
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

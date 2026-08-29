@extends('layouts.main')

@push('schema')
    @include('partials.json-ld', ['data' => [
        '@context' => 'https://schema.org',
        // Лента статей как CollectionPage: поисковик видит, что страница —
        // подборка материалов, а не статья. Позиции сквозные через страницы
        // пагинации, как в разделах (см. categories/show).
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
    ]])
@endpush

@section('content')
    <style>
        .widget-post-list .post-views-meta {
            display: block;
            margin-top: 2px;
            font-size: 0.78rem;
            color: #9aa0a6;
        }
    </style>
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Блог</h1>
            <div class="row">
                <div class="col-md-8">
                    <section>
                        <div class="row blog-post-row">

                            @foreach($posts as $post)
                                {{-- На главной карточка без анонса — компактнее, решение владельца 29.08.2026 --}}
                                @include('partials.post-card', ['post' => $post, 'withExcerpt' => false])
                            @endforeach

                        </div>

                        {{ $posts->links() }}

                    </section>
                </div>
                <div class="col-md-4 sidebar" data-aos="fade-left">
                    <div class="widget">
                        <h5 class="widget-title">Категории</h5>
                        <section class="featured-posts-section">
                            <ul>
                                @foreach($categories as $category)
                                    <li><a href="{{ route('category.show', $category->code) }}">{{ $category->title }}</a>
                                        ({{ $category->posts_count }})
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    </div>
                    <div class="widget">
                        <h5 class="widget-title">Теги</h5>
                        <section class="featured-posts-section">
                            <ul>
                                @foreach($tags as $tag)
                                    <li><a href="{{ route('tag.show', $tag->code) }}">{{ $tag->title }}</a>
                                        ({{ $tag->posts_count }})
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    </div>
                    <div class="widget widget-post-list">
                        <h5 class="widget-title">Популярные посты</h5>
                        <ul class="post-list">
                            @foreach($popularPosts as $post)
                                <li class="post">
                                    <a href="{{ route('post.show', $post->code) }}" class="post-permalink media">
                                        <x-post-image :path="$post->preview_image" :alt="$post->title"
                                                      :width="80" :height="80" sizes="80px"/>
                                        <div class="media-body">
                                            <h6 class="post-title">{{ $post->title }}</h6>
                                            <span class="post-views-meta">{{ $post->viewsLabel($post->views_count) }}</span>
                                        </div>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                </div>
            </div>
        </div>

    </main>
@endsection

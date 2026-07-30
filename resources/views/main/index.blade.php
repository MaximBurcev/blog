@extends('layouts.main')

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
                                <div class="col-md-4 fetured-post blog-post" data-aos="fade-up">
                                    <a href="{{ route('post.show', $post->code) }}">
                                        <div class="blog-post-thumbnail-wrapper">
                                            {{-- asset(), а не относительный путь: без ведущего слэша
                                                 ссылка ломалась бы на любом URL с сегментом. --}}
                                            <img src="{{ $post->preview_image ? asset('storage/'.$post->preview_image) : asset(config('seo.default_image')) }}"
                                                 alt="{{ $post->title }}" loading="lazy" decoding="async"
                                                 width="370" height="240">

                                        </div>
                                    </a>
                                    @if($post->category)
                                        <a href="{{ route('category.show', $post->category->code) }}"><p class="blog-post-category">{{ $post->category->title }}</p></a>
                                    @endif
                                    <a href="{{ route('post.show', $post->code) }}" class="blog-post-permalink">
                                        <h2 class="blog-post-title">{{ $post->title }}</h2>
                                    </a>
                                </div>
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
                                        <img src="{{ $post->preview_image ? asset('storage/'.$post->preview_image) : asset(config('seo.default_image')) }}"
                                             alt="{{ $post->title }}" loading="lazy" decoding="async"
                                             width="80" height="80">
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

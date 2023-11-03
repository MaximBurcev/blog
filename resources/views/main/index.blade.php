@extends('layouts.main')

@section('content')
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
                                            <img src="{{ 'storage/'. $post->preview_image }}" alt="{{ $post->title }}">
                                        </div>
                                    </a>
                                    <a href="{{ route('category.show', $post->category->code) }}"><p class="blog-post-category">{{ $post->category->title }}</p></a>
                                    <a href="{{ route('post.show', $post->code) }}" class="blog-post-permalink">
                                        <h6 class="blog-post-title">{{ $post->title }}</h6>
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
                                        <img src="{{ 'storage/' . $post->preview_image }}" alt="{{ $post->title }}">
                                        <div class="media-body">
                                            <h6 class="post-title">{{ $post->title }}</h6>
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

@extends('layouts.main')

@section('content')
<main class="blog">
    <div class="container">
        <h1 class="edica-page-title" data-aos="fade-up">Посты категории {{ $category->title }}</h1>
        <section class="featured-posts-section">
            <div class="row">
                @foreach($posts as $post)
                    <div class="col-md-4 fetured-post blog-post" data-aos="fade-up">
                        <div class="blog-post-thumbnail-wrapper">
                            <img src="{{ asset('storage/'. $post->preview_image) }}" alt="{{ $post->title }}">
                        </div>
                        <p class="blog-post-category">{{ $post->category->title }}</p>
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
        <br>
    </div>
</main>
@endsection

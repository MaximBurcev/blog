@extends('layouts.main')

@section('content')
    <main class="blog-post">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">{{ $post->title }}</h1>
            <p class="edica-blog-post-meta" data-aos="fade-up"
               data-aos-delay="200">{{ $date->translatedFormat('F') }} {{ $date->day }}, {{ $date->year }}
                • {{ $date->format('H:i') }} • {{ $post->comments()->count() }} Комментария</p>
            <section class="blog-post-featured-img" data-aos="fade-up" data-aos-delay="300">
                @if($post->main_image)
                    <img src="{{ asset('storage/' . $post->main_image) }}" alt="featured image" class="w-100">
                @else
                    <img src="{{ asset('storage/images/laravel.jpg') }}" alt="featured image" class="w-100">
                @endif

            </section>
            <section class="post-content">
                {!! $post->content !!}
            </section>

            @auth()
                <p>Количество пользователей, которым понравилась статья: <span id="likes-count">{{ $post->likesCount() }}</span></p>
            <button id="like-btn">❤️ Мне нравится</button>
            @endauth()

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    // Подключение Echo (после сборки Vite или через script)
                    const postId = {{ $post->id }};
                    Echo.private(`post.${postId}`)
                        .listen('.post.liked', (e) => {
                            document.getElementById('likes-count').textContent = e.newLikesCount;
                        });

                    document.getElementById('like-btn').addEventListener('click', () => {
                        fetch(`/posts/${postId}/like`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            }
                        });
                        // Локально тоже обновляем (опционально, т.к. уведомление придет от сервера)
                    });
                });
            </script>
            <div class="row">
                <div class="col-lg-9 mx-auto">
                    @if($relatedPosts->count())
                        <section class="related-posts">
                            <h2 class="section-title mb-4" data-aos="fade-up">Схожие посты</h2>
                            @foreach($relatedPosts as $relatedPost)
                                <div class="row">
                                    <div class="col-md-4" data-aos="fade-right" data-aos-delay="100">
                                        <img src="{{ asset('storage/' . $relatedPost->preview_image) }}"
                                             alt="related post" class="post-thumbnail">
                                        <p class="post-category">{{ $relatedPost->category->title }}</p>
                                        <a href="{{ route('post.show', $relatedPost->code) }}"><h5
                                                    class="post-title">{{ $relatedPost->title }}</h5></a>
                                    </div>
                                </div>
                            @endforeach
                        </section>
                    @endif
{{--                    <section class="comment-section">--}}
{{--                        <h2 class="section-title mb-5" data-aos="fade-up">Оставить комментарий</h2>--}}
{{--                        <form action="{{ route('post.comment.store', $post->id) }}" method="post">--}}
{{--                            @csrf--}}
{{--                            <div class="row">--}}
{{--                                <div class="form-group col-12" data-aos="fade-up">--}}
{{--                                    <label for="comment" class="sr-only">Сообщение</label>--}}
{{--                                    <textarea name="comment" id="comment" class="form-control" placeholder="Comment"--}}
{{--                                              rows="10">Comment</textarea>--}}
{{--                                </div>--}}
{{--                            </div>--}}
{{--                            <div class="row">--}}
{{--                                <div class="col-12" data-aos="fade-up">--}}
{{--                                    <input type="submit" value="Отправить" class="btn btn-warning">--}}
{{--                                </div>--}}
{{--                            </div>--}}
{{--                        </form>--}}
{{--                    </section>--}}
                </div>
            </div>
        </div>
    </main>
@endsection

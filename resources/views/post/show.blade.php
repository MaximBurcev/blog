@extends('layouts.main')

@section('content')
    <main class="blog-post">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">{{ $post->title }}</h1>
            <p class="edica-blog-post-meta" data-aos="fade-up"
               data-aos-delay="200">{{ $date->translatedFormat('F') }} {{ $date->day }}, {{ $date->year }}
                • {{ $date->format('H:i') }} • {{ $post->readingTimeLabel() }} • {{ $post->viewsLabel($viewsCount) }} • {{ $comments->count() }} Комментария</p>
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

            @if($post->url)
                <p class="post-original-source">Оригинал: <a href="{{ $post->url }}" target="_blank" rel="noopener noreferrer nofollow">{{ $post->url }}</a></p>
            @endif

            @auth()
                <button id="like-btn" class="post-like-btn @if($isLiked) is-liked @endif" type="button"
                        aria-pressed="{{ $isLiked ? 'true' : 'false' }}">
                    <i class="fa-heart post-like-icon {{ $isLiked ? 'fas' : 'far' }}"></i>
                    <span class="post-like-label">{{ $isLiked ? 'Нравится' : 'Мне нравится' }}</span>
                    <span class="post-like-count" id="likes-count">{{ $post->likesCount() }}</span>
                </button>

                <style>
                    .post-like-btn {
                        display: inline-flex;
                        align-items: center;
                        gap: 10px;
                        padding: 10px 20px;
                        border: 1px solid #ececec;
                        border-radius: 50px;
                        background: #fff;
                        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
                        font-weight: 600;
                        font-size: 0.95rem;
                        color: #343a40;
                        cursor: pointer;
                        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
                    }

                    .post-like-btn:hover {
                        border-color: #f29431;
                        box-shadow: 0 4px 16px rgba(242, 148, 49, 0.18);
                        transform: translateY(-1px);
                    }

                    .post-like-btn:active {
                        transform: translateY(0) scale(0.97);
                    }

                    .post-like-btn:disabled {
                        cursor: not-allowed;
                        opacity: 0.7;
                    }

                    .post-like-icon {
                        color: #c9c9c9;
                        font-size: 1.05rem;
                        transition: color 0.15s ease, transform 0.2s ease;
                    }

                    .post-like-btn:hover .post-like-icon {
                        transform: scale(1.15);
                    }

                    .post-like-btn.is-liked {
                        border-color: #f8d7e6;
                        background: #fff5fa;
                    }

                    .post-like-btn.is-liked .post-like-icon {
                        color: #e83e8c;
                    }

                    .post-like-btn.is-pop .post-like-icon {
                        animation: post-like-pop 0.35s ease;
                    }

                    .post-like-count {
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        min-width: 24px;
                        padding: 2px 8px;
                        border-radius: 50px;
                        background: #f2eee9;
                        color: #6c757d;
                        font-size: 0.85rem;
                    }

                    @keyframes post-like-pop {
                        0% { transform: scale(1); }
                        40% { transform: scale(1.35); }
                        100% { transform: scale(1); }
                    }
                </style>
            @endauth()

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    // Подключение Echo (после сборки Vite или через script)
                    const postId = {{ $post->id }};
                    Echo.private(`post.${postId}`)
                        .listen('.post.liked', (e) => {
                            document.getElementById('likes-count').textContent = e.newLikesCount;
                        });

                    const likeBtn = document.getElementById('like-btn');
                    const likeIcon = likeBtn.querySelector('.post-like-icon');
                    const likeLabel = likeBtn.querySelector('.post-like-label');

                    likeBtn.addEventListener('click', () => {
                        likeBtn.disabled = true;

                        fetch(`/posts/${postId}/like`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            }
                        })
                            .then((response) => response.json())
                            .then((data) => {
                                // broadcast(...)->toOthers() не шлёт событие обратно кликнувшему,
                                // поэтому у самого пользователя счётчик и статус лайка обновляем из
                                // ответа запроса, а не ждём Echo-события (оно только для других
                                // открытых вкладок/пользователей)
                                document.getElementById('likes-count').textContent = data.likes;

                                likeBtn.classList.toggle('is-liked', data.liked);
                                likeBtn.setAttribute('aria-pressed', data.liked ? 'true' : 'false');
                                likeIcon.classList.toggle('fas', data.liked);
                                likeIcon.classList.toggle('far', !data.liked);
                                likeLabel.textContent = data.liked ? 'Нравится' : 'Мне нравится';

                                likeBtn.classList.remove('is-pop');
                                void likeBtn.offsetWidth; // перезапуск CSS-анимации при повторном клике
                                likeBtn.classList.add('is-pop');
                            })
                            .finally(() => {
                                likeBtn.disabled = false;
                            });
                    });
                });
            </script>
            <div class="row">
                <div class="col-12">
                    @if($relatedPosts->count())
                        <section class="related-posts">
                            <h2 class="section-title mb-4" data-aos="fade-up">Схожие посты</h2>
                            <div class="row">
                                @foreach($relatedPosts as $relatedPost)
                                    <div class="col-md-3" data-aos="fade-right" data-aos-delay="100">
                                        <img src="{{ $relatedPost->preview_image ? asset('storage/' . $relatedPost->preview_image) : asset('storage/images/laravel.jpg') }}"
                                             alt="related post" class="post-thumbnail">
                                        @if($relatedPost->category)
                                            <p class="post-category">{{ $relatedPost->category->title }}</p>
                                        @endif
                                        <a href="{{ route('post.show', $relatedPost->code) }}"><h5
                                                    class="post-title">{{ $relatedPost->title }}</h5></a>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif
                    <section class="comment-section" id="comments">
                        <h2 class="section-title mb-4" data-aos="fade-up">Комментарии ({{ $comments->count() }})</h2>

                        @if(session('success'))
                            <div class="alert alert-success">{{ session('success') }}</div>
                        @endif

                        @forelse($comments as $comment)
                            <div class="comment-item" data-aos="fade-up">
                                <div class="comment-avatar">{{ mb_strtoupper(mb_substr($comment->authorName(), 0, 1)) }}</div>
                                <div class="comment-body">
                                    <div class="comment-item-header">
                                        <span class="comment-author">{{ $comment->authorName() }}</span>
                                        <span class="comment-date">{{ $comment->created_at->translatedFormat('d F Y, H:i') }}</span>
                                    </div>
                                    <p class="comment-message">{{ $comment->message }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="comment-empty" data-aos="fade-up">Пока нет комментариев — будьте первым.</p>
                        @endforelse

                        <form action="{{ route('post.comment.store', $post->id) }}" method="post" class="comment-form">
                            @csrf

                            {{-- Honeypot: невидимо человеку (offscreen + tabindex=-1 + autocomplete=off),
                                 боты-скрипты часто заполняют все найденные поля формы подряд. --}}
                            <div class="comment-hp-field" aria-hidden="true">
                                <label for="website">Website</label>
                                <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
                            </div>

                            @guest
                                <div class="row">
                                    <div class="form-group col-12">
                                        <label for="name" class="sr-only">Имя</label>
                                        <input type="text" name="name" id="name"
                                               class="form-control @error('name') is-invalid @enderror"
                                               placeholder="Ваше имя" value="{{ old('name') }}">
                                        @error('name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            @endguest

                            <div class="row">
                                <div class="form-group col-12">
                                    <label for="message" class="sr-only">Сообщение</label>
                                    <textarea name="message" id="message"
                                              class="form-control @error('message') is-invalid @enderror"
                                              placeholder="Ваш комментарий" rows="4">{{ old('message') }}</textarea>
                                    @error('message')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12" data-aos="fade-up">
                                    <input type="submit" value="Отправить" class="btn btn-warning">
                                </div>
                            </div>
                        </form>

                        <style>
                            .comment-section { margin-top: 40px; }

                            .comment-item {
                                display: flex;
                                gap: 14px;
                                padding: 16px 0;
                                border-bottom: 1px solid #ececec;
                            }

                            .comment-avatar {
                                flex-shrink: 0;
                                width: 40px;
                                height: 40px;
                                border-radius: 50%;
                                background: #f29431;
                                color: #fff;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-weight: 700;
                            }

                            .comment-item-header {
                                display: flex;
                                align-items: baseline;
                                gap: 10px;
                                margin-bottom: 4px;
                            }

                            .comment-author {
                                font-weight: 700;
                                color: #343a40;
                            }

                            .comment-date {
                                font-size: 0.8rem;
                                color: #6c757d;
                            }

                            .comment-message {
                                margin: 0;
                                white-space: pre-line;
                                word-break: break-word;
                            }

                            .comment-empty {
                                color: #6c757d;
                            }

                            .comment-form { margin-top: 24px; }

                            .comment-hp-field {
                                position: absolute;
                                left: -9999px;
                                top: -9999px;
                                width: 1px;
                                height: 1px;
                                overflow: hidden;
                            }
                        </style>
                    </section>
                </div>
            </div>
        </div>
    </main>
@endsection

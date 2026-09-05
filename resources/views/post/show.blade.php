@extends('layouts.main')

@php
    // Тело страницы: перевод или исходный текст (?lang=en). Выбирает его
    // контроллер, а PostTableOfContents возвращает то же тело с якорями у
    // заголовков (originalBody(), а не сырая колонка: записи до 27.07.2026
    // сохранялись мимо санитайзера, а картинки в оригинале ведут на чужой CDN).
    $showOriginal = $showOriginal ?? false;
    $body = $toc->content();

    // highlight.js весит 120 КБ и раньше грузился с cdnjs на каждой странице
    // сайта. Теперь он локальный и подключается только там, где есть что
    // подсвечивать — то есть в посте реально встретился блок <pre>. Считаем по
    // отображаемому телу: в оригинале листинги те же, но текст вокруг другой.
    $hasCode = str_contains((string) $body, '<pre');
@endphp

@if($hasCode)
    @push('head')
        <link rel="stylesheet" href="{{ asset('assets/vendors/highlight/androidstudio.css') }}">
    @endpush

    @push('scripts')
        <script src="{{ asset('assets/vendors/highlight/highlight.min.js') }}" defer></script>
        <script nonce="{{ $cspNonce ?? '' }}">
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.post-content pre').forEach(function (pre) {
                    // hljs подсвечивает содержимое <code>, а в контенте постов
                    // лежит голый <pre> — оборачиваем перед подсветкой.
                    if (!pre.querySelector('code')) {
                        var code = document.createElement('code');
                        code.append(...pre.childNodes);
                        pre.append(code);
                    }
                });
                hljs.highlightAll();
            });
        </script>
    @endpush
@endif

{{-- Прогресс чтения: скрипт отдельно от highlight.js, потому что полоска
     нужна всегда, а подсветка — только если в статье есть <pre>. --}}
@push('scripts')
    <script nonce="{{ $cspNonce ?? '' }}">
        document.addEventListener('DOMContentLoaded', function () {
            var bar = document.getElementById('reading-progress');
            var content = document.querySelector('.post-content');
            if (!bar || !content) return;

            var ticking = false;
            window.addEventListener('scroll', function () {
                if (!ticking) {
                    window.requestAnimationFrame(function () {
                        var rect = content.getBoundingClientRect();
                        var contentTop = rect.top + window.pageYOffset;
                        var contentHeight = content.offsetHeight;
                        var scrolled = window.pageYOffset - contentTop;
                        var viewportHeight = window.innerHeight;
                        var total = contentHeight - viewportHeight;

                        if (total <= 0) {
                            bar.style.width = '100%';
                        } else {
                            var pct = Math.min(100, Math.max(0, (scrolled / total) * 100));
                            bar.style.width = pct + '%';
                        }
                        ticking = false;
                    });
                    ticking = true;
                }
            });
        });
    </script>
@endpush

{{-- На странице оригинала разметки нет: она описывает материал блога, а тот
     живёт по адресу перевода (@id ведёт именно туда). Дублировать её на
     noindex-копии значит заявлять поисковику две страницы с одним @id. --}}
@unless($showOriginal)
@push('schema')
    @include('partials.json-ld', ['data' => [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                // Новость размечается NewsArticle, а не BlogPosting: у неё
                // свои требования к сниппету (например, она может попасть в
                // блок «Главные новости»), а материал-то новостной.
                '@type' => $post->is_news ? 'NewsArticle' : 'BlogPosting',
                '@id' => $post->permalink().'#article',
                'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $post->permalink()],
                'headline' => Str::limit($post->title, 110, ''),
                'description' => $post->excerpt(),
                'image' => $post->main_image ? asset('storage/'.$post->main_image) : asset(config('seo.default_image')),
                'datePublished' => $post->created_at?->toIso8601String(),
                'dateModified' => $post->updated_at?->toIso8601String(),
                'inLanguage' => 'ru-RU',
                // wordCount() модели, а не str_word_count(): тот кириллицу
                // не считает (см. Post::wordCount()).
                'wordCount' => $post->wordCount(),
                'author' => ['@id' => url('/').'#organization'],
                'publisher' => ['@id' => url('/').'#organization'],
                'articleSection' => $post->category?->title,
                'keywords' => $post->tags->pluck('title')->implode(', '),
                // isBasedOn — честная отметка, что это перевод чужого
                // материала, а не оригинальная публикация. sourceUrl(), а не
                // сырая колонка: в url со страницы дайджеста может лежать
                // что угодно вплоть до javascript:, и в разметке для
                // поисковика такому адресу не место.
                'isBasedOn' => $post->sourceUrl(),
            ],
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_values(array_filter([
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => url('/')],
                    $post->category ? ['@type' => 'ListItem', 'position' => 2, 'name' => $post->category->title, 'item' => route('category.show', $post->category->code)] : null,
                    ['@type' => 'ListItem', 'position' => $post->category ? 3 : 2, 'name' => $post->title],
                ])),
            ],
        ],
    ]])
@endpush
@endunless

@section('content')
    {{-- Прогресс чтения: тонкая полоска сверху, заполняется по мере прокрутки
         контента статьи. Только визуальная обратная связь — ничего не считает,
         данных не отправляет. --}}
    <div class="reading-progress" id="reading-progress" aria-hidden="true"></div>
    <style>
        .reading-progress {
            position: fixed;
            top: 0;
            left: 0;
            width: 0;
            height: 3px;
            background: #f29431;
            z-index: 9999;
            transition: none;
            pointer-events: none;
        }
    </style>

    <main class="blog-post">
        <div class="container">
            <nav aria-label="Хлебные крошки" class="edica-breadcrumbs">
                <a href="{{ route('main.index') }}">Главная</a>
                @if($post->category)
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('category.show', $post->category->code) }}">{{ $post->category->title }}</a>
                @endif
            </nav>
            <h1 class="edica-page-title" data-aos="fade-up">{{ $post->title }}</h1>
            <p class="edica-blog-post-meta" data-aos="fade-up"
               data-aos-delay="200">{{ $date->translatedFormat('F') }} {{ $date->day }}, {{ $date->year }}
                • {{ $date->format('H:i') }} • {{ $post->readingTimeLabel() }} • {{ $post->viewsLabel($viewsCount) }} • {{ $commentsCount }} Комментария</p>

            {{-- Переключатель языка: видимая кнопка вместо голого ?lang=en.
                 Показывается только если у поста есть сохранённый оригинал. --}}
            @if($post->hasOriginal())
                <div class="post-lang-toggle" data-aos="fade-up" data-aos-delay="200">
                    @if($showOriginal)
                        <a href="{{ $post->permalink() }}" class="post-lang-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                            Читать перевод
                        </a>
                    @else
                        <a href="{{ $post->originalPermalink() }}" class="post-lang-btn" rel="nofollow">
                            Читать оригинал
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    @endif
                </div>
                <style>
                    .post-lang-toggle {
                        margin-bottom: 1rem;
                    }

                    .post-lang-btn {
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        padding: 6px 16px;
                        border: 1px solid #ececec;
                        border-radius: 50px;
                        background: #fff;
                        font-size: 0.9rem;
                        font-weight: 600;
                        color: #343a40;
                        text-decoration: none;
                        transition: border-color 0.15s ease, color 0.15s ease;
                    }

                    .post-lang-btn:hover {
                        border-color: #f29431;
                        color: #f29431;
                        text-decoration: none;
                    }
                </style>
            @endif

            {{-- Обложки в теле статьи нет намеренно.

                 У переводных статей это og:image площадки-источника, а такая
                 картинка по построению состоит из заголовка статьи, имени
                 автора и логотипа площадки. То есть прямо под <h1> висел тот
                 же самый заголовок ещё раз — по-английски, вразрез с языком
                 страницы, вместе с чужим брендингом. Перевод картинки проблему
                 не решал, а усугублял: дубль становился русским и с огрехами
                 распознавания («GeoIP» → «GeoP»).

                 Картинка осталась там, где несёт пользу и не дублирует текст:
                 в og:image и JSON-LD (превью ссылки в мессенджерах и соцсетях)
                 и в карточках листингов. Побочно ушёл и LCP-элемент: самым
                 большим объектом первого экрана была картинка с текстом,
                 который и так есть разметкой.

                 Новостей (/news/{code}, тот же шаблон) это касается ровно так
                 же: они приходят тем же StorePostJob, и обложка у них — такой
                 же og:image источника.

                 Содержательные картинки внутри статьи это не затрагивает — они
                 живут в content и выводятся ниже. --}}
            {{-- Оглавление: только у длинных статей (порог — в
                 PostTableOfContents), ссылки — якоря на id, которые тот же
                 класс проставил заголовкам в $body. --}}
            @if($toc->items())
                <nav class="post-toc" aria-label="Оглавление" data-aos="fade-up">
                    <h2 class="post-toc-title">Содержание</h2>
                    <ol class="post-toc-list">
                        @foreach($toc->items() as $tocItem)
                            <li class="post-toc-item post-toc-level-{{ $tocItem['level'] }}">
                                <a href="#{{ $tocItem['id'] }}">{{ $tocItem['title'] }}</a>
                            </li>
                        @endforeach
                    </ol>
                </nav>
                <style>
                    .post-toc {
                        margin-bottom: 1.5rem;
                        padding: 1rem 1.25rem;
                        border-left: 3px solid #f29431;
                        background-color: #f6f7f9;
                    }

                    .post-toc-title {
                        margin: 0 0 0.5rem;
                        font-size: 1.1rem;
                    }

                    .post-toc-list {
                        margin: 0;
                        padding-left: 1.25rem;
                    }

                    .post-toc-level-3 {
                        margin-left: 1.25rem;
                        list-style: circle;
                    }
                </style>
            @endif

            {{-- lang на секции, а не на <html>: заголовок, меню и подписи
                 вокруг остаются русскими, английский тут только у тела
                 статьи. Для скринридера это разница между английским
                 произношением и русским по буквам. --}}
            <section class="post-content"@if($showOriginal) lang="{{ \App\Models\Post::ORIGINAL_LANG }}"@endif>
                {!! $body !!}
            </section>

            {{-- Схему адреса проверяет sourceUrl(): url приходит со страницы
                 стороннего дайджеста, а Blade экранирует кавычки, но не
                 схему — `javascript:` в href остался бы рабочим. --}}
            @if($post->sourceUrl())
                <p class="post-original-source">Оригинал: <a href="{{ $post->sourceUrl() }}" target="_blank" rel="noopener noreferrer nofollow">{{ $post->sourceUrl() }}</a></p>
            @endif

            {{-- Ссылки на категорию и теги: без них страница поста была
                 тупиком — ни посетителю, ни краулеру идти отсюда некуда. --}}
            @if($post->category || $post->tags->isNotEmpty())
                <p class="post-taxonomy">
                    @if($post->category)
                        <span class="post-taxonomy-label">Категория:</span>
                        <a href="{{ route('category.show', $post->category->code) }}">{{ $post->category->title }}</a>
                    @endif
                    @if($post->tags->isNotEmpty())
                        <span class="post-taxonomy-label">Теги:</span>
                        @foreach($post->tags as $tag)
                            <a href="{{ route('tag.show', $tag->code) }}">{{ $tag->title }}</a>{{ $loop->last ? '' : ',' }}
                        @endforeach
                    @endif
                </p>
            @endif

            {{-- Шаринг — только голые ссылки на шаринговые эндпоинты, без
                 сторонних JS-виджетов: иначе каждый читатель статьи грузил бы
                 скрипты соцсетей (CSP + приватность). Адрес берём из
                 permalink() — он уже учитывает раздел (статья/новость). --}}
            <div class="post-share">
                <span class="post-share-label">Поделиться:</span>
                <a class="post-share-link" target="_blank" rel="noopener noreferrer nofollow"
                   href="https://t.me/share/url?url={{ urlencode($post->permalink()) }}&text={{ urlencode($post->title) }}">Telegram</a>
                <a class="post-share-link" target="_blank" rel="noopener noreferrer nofollow"
                   href="https://vk.com/share.php?url={{ urlencode($post->permalink()) }}">VK</a>
                <button type="button" class="post-share-link post-share-copy" id="copy-link-btn"
                        data-url="{{ $post->permalink() }}">Копировать ссылку</button>
            </div>
            <script nonce="{{ $cspNonce ?? '' }}">
                document.addEventListener('DOMContentLoaded', function () {
                    var copyBtn = document.getElementById('copy-link-btn');
                    var label = copyBtn.textContent;

                    copyBtn.addEventListener('click', function () {
                        navigator.clipboard.writeText(copyBtn.dataset.url).then(function () {
                            copyBtn.textContent = 'Ссылка скопирована';
                            setTimeout(function () { copyBtn.textContent = label; }, 2000);
                        });
                    });
                });
            </script>
            <style>
                .post-share {
                    display: flex;
                    align-items: center;
                    flex-wrap: wrap;
                    gap: 10px;
                    margin-top: 1.5rem;
                }

                .post-share-label {
                    color: #6c757d;
                    font-size: 0.9rem;
                }

                .post-share-link {
                    display: inline-block;
                    padding: 6px 14px;
                    border: 1px solid #ececec;
                    border-radius: 50px;
                    background: #fff;
                    font-size: 0.9rem;
                    font-weight: 600;
                    color: #343a40;
                    cursor: pointer;
                }

                .post-share-link:hover {
                    border-color: #f29431;
                    color: #f29431;
                }
            </style>

            @auth()
                <button id="like-btn" class="post-like-btn @if($isLiked) is-liked @endif" type="button"
                        aria-pressed="{{ $isLiked ? 'true' : 'false' }}">
                    {{-- Инлайновый SVG вместо иконки Font Awesome: ради одного
                         сердечка весь шрифт иконок грузился на каждой странице
                         сайта и блокировал отрисовку. Залито/не залито сердце
                         решает класс .is-liked на кнопке, отдельные классы
                         иконке больше не нужны. --}}
                    <svg class="post-like-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                    </svg>
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
                        fill: none;
                        stroke: currentColor;
                        stroke-width: 2;
                        stroke-linejoin: round;
                        transition: color 0.15s ease, fill 0.15s ease, transform 0.2s ease;
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
                        fill: currentColor;
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

            @auth
            {{-- Скрипт лайков только для авторизованных: у гостя нет ни кнопки
                 (#like-btn отсутствует → likeBtn.querySelector падал с
                 TypeError и ронял весь обработчик DOMContentLoaded), ни
                 авторизации на приватный канал Echo. --}}
            <script nonce="{{ $cspNonce ?? '' }}">
                document.addEventListener('DOMContentLoaded', () => {
                    // Подключение Echo (после сборки Vite или через script)
                    const postId = {{ $post->id }};
                    Echo.private(`post.${postId}`)
                        .listen('.post.liked', (e) => {
                            document.getElementById('likes-count').textContent = e.newLikesCount;
                        });

                    const likeBtn = document.getElementById('like-btn');
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
            @endauth
            <div class="row">
                <div class="col-12">
                    @if($relatedPosts->count())
                        <section class="related-posts">
                            <h2 class="section-title mb-4" data-aos="fade-up">Схожие посты</h2>
                            <div class="row">
                                @foreach($relatedPosts as $relatedPost)
                                    <div class="col-md-3" data-aos="fade-right" data-aos-delay="100">
                                        <x-post-image :path="$relatedPost->preview_image" :alt="$relatedPost->title"
                                                      :width="270" :height="180" class="post-thumbnail"
                                                      sizes="(max-width: 767px) 50vw, 270px"/>
                                        @if($relatedPost->category)
                                            <p class="post-category">{{ $relatedPost->category->title }}</p>
                                        @endif
                                        <a href="{{ $relatedPost->permalink() }}"><h3
                                                    class="post-title">{{ $relatedPost->title }}</h3></a>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif
                    {{-- Переход по ленте: соседи по дате публикации внутри
                         своего типа (новости/статьи), подбирает контроллер. --}}
                    @if($previousPost || $nextPost)
                        <nav class="post-neighbors" aria-label="Предыдущая и следующая публикация">
                            @if($previousPost)
                                <a class="post-neighbor" rel="prev" href="{{ $previousPost->permalink() }}">
                                    <span class="post-neighbor-label">← Предыдущая</span>
                                    <span class="post-neighbor-title">{{ $previousPost->title }}</span>
                                </a>
                            @endif
                            @if($nextPost)
                                <a class="post-neighbor post-neighbor-next" rel="next" href="{{ $nextPost->permalink() }}">
                                    <span class="post-neighbor-label">Следующая →</span>
                                    <span class="post-neighbor-title">{{ $nextPost->title }}</span>
                                </a>
                            @endif
                        </nav>
                        <style>
                            .post-neighbors {
                                display: flex;
                                justify-content: space-between;
                                gap: 20px;
                                margin-top: 40px;
                            }

                            .post-neighbor {
                                max-width: 48%;
                                padding: 14px 18px;
                                border: 1px solid #ececec;
                                border-radius: 8px;
                                color: #343a40;
                            }

                            .post-neighbor:hover {
                                border-color: #f29431;
                            }

                            .post-neighbor-next {
                                margin-left: auto;
                                text-align: right;
                            }

                            .post-neighbor-label {
                                display: block;
                                font-size: 0.8rem;
                                color: #6c757d;
                            }

                            .post-neighbor-title {
                                font-weight: 600;
                            }
                        </style>
                    @endif
                    <section class="comment-section" id="comments">
                        <h2 class="section-title mb-4" data-aos="fade-up">Комментарии ({{ $commentsCount }})</h2>

                        @if(session('success'))
                            <div class="alert alert-success">{{ session('success') }}</div>
                        @endif

                        {{-- Пагинация только по корневым комментариям: ответы
                             едут вместе с родителем, поэтому ветка никогда не
                             разрывается между страницами. --}}
                        @forelse($comments as $comment)
                            @include('post.comment', ['comment' => $comment])
                            @if($comment->replies->isNotEmpty())
                                <div class="comment-replies">
                                    @foreach($comment->replies as $reply)
                                        @include('post.comment', ['comment' => $reply, 'isReply' => true])
                                    @endforeach
                                </div>
                            @endif
                        @empty
                            <p class="comment-empty" data-aos="fade-up">Пока нет комментариев — будьте первым.</p>
                        @endforelse

                        @if($comments->hasPages())
                            {{ $comments->links() }}
                        @endif

                        <form action="{{ route('post.comment.store', $post->id) }}" method="post" class="comment-form" id="comment-form">
                            @csrf

                            {{-- Ответ: parent_id проставляет скрипт по клику
                                 на «Ответить» у комментария, плашка показывает,
                                 кому отвечаем. Без JS форма работает как раньше —
                                 комментарием к самому посту. --}}
                            <input type="hidden" name="parent_id" id="comment-parent-id" value="{{ old('parent_id') }}">
                            <p class="comment-reply-hint" id="comment-reply-hint" hidden>
                                Вы отвечаете на комментарий <b id="comment-reply-author"></b>.
                                <button type="button" class="comment-reply-cancel" id="comment-reply-cancel">Отменить</button>
                            </p>
                            @error('parent_id')
                                <div class="alert alert-danger">{{ $message }}</div>
                            @enderror

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

                        <script nonce="{{ $cspNonce ?? '' }}">
                            document.addEventListener('DOMContentLoaded', function () {
                                var form = document.getElementById('comment-form');
                                var parentInput = document.getElementById('comment-parent-id');
                                var hint = document.getElementById('comment-reply-hint');
                                var author = document.getElementById('comment-reply-author');

                                document.querySelectorAll('.comment-reply-btn').forEach(function (btn) {
                                    btn.addEventListener('click', function () {
                                        parentInput.value = btn.dataset.replyId;
                                        author.textContent = btn.dataset.replyAuthor;
                                        hint.hidden = false;
                                        form.scrollIntoView({ behavior: 'smooth' });
                                        document.getElementById('message').focus();
                                    });
                                });

                                document.getElementById('comment-reply-cancel').addEventListener('click', function () {
                                    parentInput.value = '';
                                    hint.hidden = true;
                                });
                            });
                        </script>

                        <style>
                            .comment-section { margin-top: 40px; }

                            .comment-replies {
                                margin-left: 54px;
                            }

                            .comment-reply-btn {
                                padding: 0;
                                border: 0;
                                background: none;
                                color: #f29431;
                                font-size: 0.85rem;
                                cursor: pointer;
                            }

                            .comment-reply-hint {
                                padding: 8px 12px;
                                background: #f6f7f9;
                                border-left: 3px solid #f29431;
                                font-size: 0.9rem;
                            }

                            .comment-reply-cancel {
                                padding: 0;
                                border: 0;
                                background: none;
                                color: #6c757d;
                                text-decoration: underline;
                                cursor: pointer;
                            }

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

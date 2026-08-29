{{--
    Карточка поста листинга: главная, категория, тег.

    Анонс и время чтения считаются от content (Post::excerpt(),
    Post::readingTimeLabel()), поэтому content обязан быть в select()
    контроллера листинга — иначе оба метода молча вернут пустоту.
    Дата — published_at с фолбэком на created_at, как в ленте новостей.
    $loop->first приезжает из оборачивающего @foreach: первая карточка —
    кандидат в LCP, и откладывать её загрузку нечем.
--}}
<div class="col-md-4 fetured-post blog-post" data-aos="fade-up">
    <a href="{{ $post->permalink() }}">
        <div class="blog-post-thumbnail-wrapper">
            <x-post-image :path="$post->preview_image" :alt="$post->title"
                          :width="370" :height="240"
                          :loading="isset($loop) && $loop->first ? 'eager' : 'lazy'"
                          :fetchpriority="isset($loop) && $loop->first ? 'high' : null"/>
        </div>
    </a>
    @if($post->category)
        <a href="{{ route('category.show', $post->category->code) }}"><p class="blog-post-category">{{ $post->category->title }}</p></a>
    @endif
    <a href="{{ $post->permalink() }}" class="blog-post-permalink">
        <h2 class="blog-post-title">{{ $post->title }}</h2>
    </a>
    <p class="text-muted small mb-1">
        <time datetime="{{ ($post->published_at ?? $post->created_at)->toDateString() }}">
            {{ ($post->published_at ?? $post->created_at)->translatedFormat('j F Y') }}
        </time>
        · {{ $post->readingTimeLabel() }}
    </p>
    <p class="blog-post-excerpt">{{ $post->excerpt() }}</p>
</div>

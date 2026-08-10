@extends('layouts.main')

@section('content')
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Новости</h1>

            <p class="text-muted" data-aos="fade-up">
                Короткие новости и анонсы мира PHP. Каждая ведёт на первоисточник.
            </p>

            @forelse($news as $item)
                <article class="news-item mb-4 pb-4 border-bottom" data-aos="fade-up">
                    <h2 class="h5 mb-1">
                        {{-- rel="nofollow": ссылки ведут наружу и добавляются
                             автоматически, передавать им вес не нужно. --}}
                        <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer nofollow">
                            {{ $item->title }}
                        </a>
                    </h2>

                    <div class="text-muted small mb-2">
                        <time datetime="{{ $item->created_at->toDateString() }}">
                            {{ $item->created_at->translatedFormat('j F Y') }}
                        </time>
                        @if($item->source_host)
                            · {{ $item->source_host }}
                        @endif
                    </div>

                    {{-- summary проходит через HtmlSanitizerService в мутаторе
                         модели, поэтому выводим как есть: там уже нет ни
                         скриптов, ни обработчиков событий. --}}
                    <p class="mb-0">{{ $item->summary }}</p>
                </article>
            @empty
                <p data-aos="fade-up">Новостей пока нет.</p>
            @endforelse

            <div class="row">
                <div class="col-md-12">
                    {{ $news->links() }}
                </div>
            </div>
        </div>
    </main>
@endsection

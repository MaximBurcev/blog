@extends('layouts.main')

@push('schema')
    @include('partials.json-ld', ['data' => [
        '@context' => 'https://schema.org',
        // См. categories/show: листинг-CollectionPage, позиции сквозь
        // пагинацию. permalink() отдаёт новостям их адрес /news/{code}.
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
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Новости</h1>

            <p class="text-muted" data-aos="fade-up">
                Новости и анонсы мира PHP в переводе на русский.
            </p>

            @forelse($posts as $post)
                <article class="news-item mb-4 pb-4 border-bottom" data-aos="fade-up">
                    <h2 class="h5 mb-1">
                        <a href="{{ route('news.show', $post->code) }}">{{ $post->title }}</a>
                    </h2>

                    <div class="text-muted small mb-2">
                        <time datetime="{{ $post->created_at->toDateString() }}">
                            {{ $post->created_at->translatedFormat('j F Y') }}
                        </time>
                        @if($post->url)
                            · {{ parse_url($post->url, PHP_URL_HOST) }}
                        @endif
                    </div>
                </article>
            @empty
                <p data-aos="fade-up">Новостей пока нет.</p>
            @endforelse

            <div class="row">
                <div class="col-md-12">
                    {{ $posts->links() }}
                </div>
            </div>
        </div>
    </main>
@endsection

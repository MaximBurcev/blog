@extends('layouts.main')

@push('schema')
    @include('partials.json-ld', ['data' => [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Главная', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Инструменты'],
        ],
    ]])
@endpush

@section('content')
    <main class="blog">
        <div class="container">
            <nav aria-label="Хлебные крошки" class="edica-breadcrumbs">
                <a href="{{ route('main.index') }}">Главная</a>
                <span aria-hidden="true">/</span>
                <span>Инструменты</span>
            </nav>

            <h1 class="edica-page-title" data-aos="fade-up">Инструменты</h1>

            <div class="row">
                @forelse($tools as $tool)
                    <div class="col-md-12" data-aos="fade-up">
                        <article class="tool-item mb-4 pb-4 border-bottom">
                            <h2 class="h5 mb-1">
                                <a href="{{ $tool->url }}" target="_blank" rel="noopener">{{ $tool->name }}</a>
                            </h2>

                            <p class="mb-1">{{ $tool->displayDescription() }}</p>

                            <div class="text-muted small">
                                {{ parse_url($tool->url, PHP_URL_HOST) }}
                            </div>
                        </article>
                    </div>
                @empty
                    <div class="col-md-12">
                        <p data-aos="fade-up">Инструментов пока нет.</p>
                    </div>
                @endforelse
            </div>

            <div class="row">
                <div class="col-md-12">
                    {{ $tools->links() }}
                </div>
            </div>
        </div>
    </main>
@endsection

@extends('layouts.main')

@section('content')
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Поиск</h1>

            <form action="{{route('main.search') }}">

                <div class="row">
                    <div class="form-group col-md-12 aos-init aos-animate" data-aos="fade-up">

                        <input type="text" class="form-control" id="q" name="q" placeholder="Что ищете?"
                               {{-- $query, а не request('q'): контроллер уже
                                    нормализовал ввод (строка, обрезанная по
                                    длине), а сырой параметр может прийти
                                    массивом и уронить рендер. --}}
                               value="{{ $query }}">
                    </div>

                </div>

                <div class="row">
                    <div class="form-group col-md-6 aos-init aos-animate" data-aos="fade-up">
                        <button type="submit" class="btn btn-warning btn-lg aos-init aos-animate" data-aos="fade-up"
                                data-aos-delay="300">Найти
                        </button>
                    </div>

                </div>


            </form>

            <div class="row">
                <div class="col-md-12">
                    <section>
                        <div class="row blog-post-row">


                            @if($searchFailed)

                                <p>Поиск временно недоступен, попробуйте позже.</p>

                            @elseif($query !== '')

                                {{-- Раньше здесь стояло @if($posts): пустая
                                     коллекция — это объект, то есть условие
                                     всегда истинно, и вместо «ничего не найдено»
                                     показывалась пустая сетка. --}}
                                @if($posts->isNotEmpty())

                                    @foreach($posts as $post)
                                        <div class="col-md-4 fetured-post blog-post" data-aos="fade-up">
                                            <a href="{{ route('post.show', $post->code) }}">
                                                <div class="blog-post-thumbnail-wrapper">
                                                    <x-post-image :path="$post->preview_image" :alt="$post->title"
                                                                  :width="370" :height="240"/>

                                                </div>
                                            </a>
                                            @if($post->category)
                                                <a href="{{ route('category.show', $post->category->code) }}"><p
                                                        class="blog-post-category">{{ $post->category->title }}</p></a>
                                            @endif
                                            <a href="{{ route('post.show', $post->code) }}" class="blog-post-permalink">
                                                <h2 class="blog-post-title">{{ $post->title }}</h2>
                                            </a>
                                        </div>
                                    @endforeach

                                @else

                                    <p>Ничего не найдено</p>

                                @endif

                            @endif

                        </div>

                        @if($posts->hasPages())
                            <div class="row">
                                <div class="col-md-12">
                                    {{ $posts->links() }}
                                </div>
                            </div>
                        @endif

                    </section>
                </div>

            </div>
            <br><br>
        </div>

    </main>
@endsection

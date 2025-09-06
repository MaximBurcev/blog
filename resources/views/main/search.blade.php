@extends('layouts.main')

@section('content')
    <main class="blog">
        <div class="container">
            <h1 class="edica-page-title" data-aos="fade-up">Поиск</h1>

            <form action="{{route('main.search') }}">

                <div class="row">
                    <div class="form-group col-md-12 aos-init aos-animate" data-aos="fade-up">

                        <input type="text" class="form-control" id="q" name="q" placeholder="Что ищете?"
                               value="{{request('q')}}">
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


                            @if(request('q'))

                                @if($posts)

                                    @foreach($posts as $post)
                                        <div class="col-md-4 fetured-post blog-post" data-aos="fade-up">
                                            <a href="{{ route('post.show', $post->code) }}">
                                                <div class="blog-post-thumbnail-wrapper">
                                                    @if($post->preview_image)
                                                        <img src="{{ 'storage/'. $post->preview_image }}"
                                                             alt="{{ $post->title }}">
                                                    @else
                                                        <img src="{{ 'storage/images/laravel.jpg' }}"
                                                             alt="{{ $post->title }}">
                                                    @endif

                                                </div>
                                            </a>
                                            <a href="{{ route('category.show', $post->category->code) }}"><p
                                                    class="blog-post-category">{{ $post->category->title }}</p></a>
                                            <a href="{{ route('post.show', $post->code) }}" class="blog-post-permalink">
                                                <h6 class="blog-post-title">{{ $post->title }}</h6>
                                            </a>
                                        </div>
                                    @endforeach

                                @else

                                    <p>Ничего не найдено</p>

                                @endif

                            @endif

                        </div>


                    </section>
                </div>

            </div>
            <br><br>
        </div>

    </main>
@endsection

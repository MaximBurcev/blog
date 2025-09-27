@extends('admin.layouts.main')

@section('content')
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Редактирование поста</h1>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.main.index') }}">Главная</a></li>
                        <li class="breadcrumb-item active">Редактирование поста</li>
                    </ol>
                </div><!-- /.col -->
            </div><!-- /.row -->
        </div><!-- /.container-fluid -->
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">

            <div class="row">

                <form action="{{ route('admin.post.update', $post->id) }}" method="post" enctype="multipart/form-data">
                    @csrf
                    @method('PATCH')
                    <div class="form-group">
                        <label>Название</label>
                        <input type="text" class="form-control" name="title" value="{{ $post->title }}" placeholder="Название поста">
                        @error('title')
                            <div class="text-danger">Это поле необходимо для заполнения</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label>Символьный код</label>
                        <input type="text" class="form-control" name="code" value="{{ $post->code }}" placeholder="Символьный код">
                        @error('code')
                        <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label>Контент</label>
                        <textarea id="summernote" name="content">{{ $post->content }}</textarea>
                        @error('content')
                        <div class="text-danger">Это поле необходимо для заполнения</div>
                        @enderror
                    </div>
                    <div class="form-group w-50">
                        <label for="exampleInputFile">Добавить превью</label>
                        @if($post->preview_image)
                        <div class="w-25 mb-2">
                            <img class="w-50" src="{{ asset('storage/' . $post->preview_image) }}" alt="preview_image">
                        </div>
                        @endif
                        <div class="input-group">
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="preview_image" id="preview_image">
                                <label class="custom-file-label" for="exampleInputFile">Выберите изображение</label>
                            </div>
                            <div class="input-group-append">
                                <span class="input-group-text">Загрузить</span>
                            </div>
                        </div>
                        @error('preview_image')
                        <div class="text-danger">Это поле необходимо для заполнения</div>
                        @enderror
                    </div>
                    <div class="form-group w-50">
                        <label for="exampleInputFile">Добавить изображение</label>
                        @if($post->main_image)
                        <div class="w-25 mb-2">
                            <img class="w-50" src="{{ url('storage/' . $post->main_image) }}" alt="main_image">
                        </div>
                        @endif
                        <div class="input-group">
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="main_image" id="main_image">
                                <label class="custom-file-label" for="exampleInputFile">Выберите изображение</label>
                            </div>
                            <div class="input-group-append">
                                <span class="input-group-text">Загрузить</span>
                            </div>
                        </div>
                        @error('main_image')
                        <div class="text-danger">Это поле необходимо для заполнения</div>
                        @enderror
                    </div>
                    <div class="form-group w-50">
                        <label>Выберите категорию</label>
                        <select class="form-control" name="category_id">
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}"
                                        {{ $category->id == $post->category_id ? ' selected':'' }}
                                >{{ $category->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group w-50">
                        <label>Теги</label>
                        <select class="select2" name="tag_ids[]" multiple="multiple" data-placeholder="Выберите теги" style="width: 100%;">
                            @foreach($tags as $tag)
                                <option {{ is_array($post->tags->pluck('id')->toArray()) && in_array($tag->id, $post->tags->pluck('id')->toArray())? ' selected':'' }} value="{{ $tag->id }}">{{ $tag->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group w-50">
                        <label>Опубликовано: </label>
                        <input type="hidden" name="published" value="0">
                        <input type="checkbox" name="published" value="1" @checked($post->published)>
                        @error('published')
                        <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label for="translate">Сделать перевод</label>
                        <input type="hidden" name="translate" value="0">
                        <input type="checkbox" name="translate" id="translate">
                    </div>
                    <div class="form-group">
                        <input type="submit" class="btn btn-primary" value="Обновить">
                    </div>
                </form>

            </div>


        </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->
@endsection

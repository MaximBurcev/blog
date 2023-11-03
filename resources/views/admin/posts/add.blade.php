@extends('admin.layouts.main')

@section('content')
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Добавление поста</h1>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.main.index') }}">Главная</a></li>
                        <li class="breadcrumb-item"><a href="{{ route('admin.post.index') }}">Посты</a></li>
                        <li class="breadcrumb-item active">Добавление поста</li>
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

                <form action="{{ route('admin.post.store.add') }}" method="post" enctype="multipart/form-data" class="col-6">
                    @csrf
                    <div class="form-group">
                        <label>URL страницы с постом:</label>
                        <input type="text" class="form-control" name="url" placeholder="URL страницы с постом" value="{{ old('url') }}">
                        @error('url')
                        <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label>Селектор для контента:</label>
                        <input type="text" class="form-control" name="selector" placeholder="Селектор для контента" value="{{ old('selector') }}">
                        @error('selector')
                        <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <input type="hidden" name="tag_ids[]" value="4" >
                        <input type="submit" class="btn btn-primary" value="Добавить">
                    </div>
                </form>

            </div>


        </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->
@endsection

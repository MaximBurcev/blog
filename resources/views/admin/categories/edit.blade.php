@extends('admin.layouts.main')

@section('content')
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Редактирование категории</h1>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="#">Home</a></li>
                        <li class="breadcrumb-item active">Dashboard v1</li>
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

                <form action="{{ route('admin.category.update', $category->id) }}" method="post">
                    @csrf
                    @method('PATCH')
                    <div class="form-group">
                        <label for="exampleInputEmail1">Название</label>
                        <input type="text" class="form-control" name="title" value="{{ $category->title }}" placeholder="Название категории">
                        @error('title')
                            <div class="text-danger">Это поле необходимо для заполнения</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label for="exampleInputEmail1">Символьный код</label>
                        <input type="text" class="form-control" name="code" value="{{ $category->code }}" placeholder="Символьный код">
                        @error('code')
                        <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <input type="submit" class="btn btn-primary" value="Обновить">
                </form>

            </div>


        </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->
@endsection

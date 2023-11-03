@extends('admin.layouts.main')

@section('content')
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Редактирование пользователя</h1>
                </div><!-- /.col -->
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.main.index') }}">Главная</a></li>
                        <li class="breadcrumb-item active">Пользователи</li>
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
                <form action="{{ route('admin.user.update', $user->id) }}" method="post">
                    @csrf
                    @method('PATCH')
                    <div class="form-group">
                        <label for="exampleInputEmail1">Имя</label>
                        <input type="text" class="form-control" name="name" value="{{ $user->name }}" placeholder="Имя пользователя">
                        @error('name')
                            <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group">
                        <label for="exampleInputEmail1">Email</label>
                        <input type="text" class="form-control" name="email" value="{{ $user->email }}" placeholder="Email пользователя">
                        @error('email')
                        <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group w-50">
                        <label>Выберите роль</label>
                        <select class="form-control" name="role">
                            @foreach($roles as $id => $value)
                                <option value="{{ $id }}"
                                        {{ $id == $user->role? ' selected':'' }}
                                >{{ $value }}</option>
                            @endforeach
                        </select>
                        @error('role')
                        <div class="text-danger">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="form-group w-50">
                        <input type="hidden" name="user_id" value="{{ $user->id }}">
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

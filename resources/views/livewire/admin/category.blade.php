<section class="content">
    <div class="container-fluid">

        <div class="row">

            <div class="col-2 mb-3">
                <form action="{{ route('admin.category.store') }}" wire:submit.prevent="add" method="post">
                    @csrf
                    <div class="form-group">

                        <input type="text" class="form-control" name="title" placeholder="Название категории" wire:model="title">
                        @error('title')
                        <div class="text-danger">Необходимо указать название категории</div>
                        @enderror
                    </div>
                    <div class="form-group">

                        <input type="text" class="form-control" name="code" placeholder="Символьный код категории" wire:model="code">
                        @error('code')
                        <div class="text-danger">Необходимо указать символьный код категории</div>
                        @enderror
                    </div>
                    <input type="submit" class="btn btn-primary" value="Добавить">
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-4">
                <div class="card">
                    <div class="card-body table-responsive p-0">
                        <table class="table table-hover text-nowrap">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Код</th>
                                <th class="text-center">Действие</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($categories as $category)
                                <tr wire:key="{{ $category->id }}">
                                    <td>{{ $category->id }}</td>
                                    <td>{{ $category->title }}</td>
                                    <td>{{ $category->code }}</td>
                                    <td class="text-center">
                                            <button wire:click="delete({{ $category->id }})" wire:confirm="Точно хотите удалить категорию?" class="border-0 bg-transparent">
                                                Удалить
                                            </button>


                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <!-- /.card-body -->
                </div>
            </div>

        </div>


    </div><!-- /.container-fluid -->
</section>

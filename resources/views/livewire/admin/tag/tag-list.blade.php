<section class="content">
    <div class="container-fluid">

        <div class="row">

            <div class="col-2 mb-3">
                <div class="row">

                    <form action="{{ route('admin.tag.store') }}" wire:submit.prevent="addTag" method="post">
                        @csrf
                        <div class="form-group">
                            <input type="text" class="form-control" name="title" placeholder="Название тега" wire:model="title">

                            @error('title')
                            <div class="text-danger">Это поле необходимо для заполнения</div>
                            @enderror

                        </div>
                        <input type="submit" class="btn btn-primary" value="Добавить">
                    </form>

                </div>

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
                            @foreach($tags as $tag)
                                <tr wire:key="{{ $tag->id }}">
                                    <td>{{ $tag->id }}</td>
                                    <td>{{ $tag->title }}</td>
                                    <td>{{ $tag->code }}</td>
                                    <td class="text-center">
                                        <div>
                                            <button type="button" class="border-0 bg-transparent"
                                                    wire:click="deleteTag({{$tag->id}})"
                                                    wire:confirm="Вы действительно хотите удалить тег?">
                                                <i class="fas fa-trash text-danger" role="button">Удалить</i>
                                            </button>
                                        </div>

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

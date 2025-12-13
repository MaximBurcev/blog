<div class="row">
    <div class="col-12">

        <div class="d-flex justify-content-between mb-2" id="posts-list">
            <div>
                {{-- <select class="form-select" wire:change="changeLimit($event.target.value)"> --}}
                <select class="form-select form-control" wire:model="limit" wire:change="changeLimit">
                    @foreach($limitList as $k => $v)
                        <option @if($v == $limit) selected @endif wire:key="{{ $k }}" value="{{ $v }}">{{ $v }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <input type="text" class="form-control" id="search" placeholder="Search..." wire:model.live.debounce.300ms="search">
            </div>
        </div>

        <div class="card">
            <div class="card-body table-responsive p-0">

                <div wire:loading style="position: absolute; width: 100%; height: 100%; background: rgba(255, 255, 255, .7); text-align: center; padding-top: 20px;">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden"></span>
                    </div>
                </div>

                <table class="table table-hover text-nowrap">
                    <thead>
                    <tr>
                        <th wire:click="changeOrder('posts.id')" style="cursor:pointer;">
                            <x-sort-arrows fieldName="ID" :orderByField="$orderByField" :orderByDirection="$orderByDirection" :orderByFieldList="$orderByFieldList"/>
                        </th>
                        <th wire:click="changeOrder('posts.title')" style="cursor:pointer;">
                            <x-sort-arrows fieldName="Название" :orderByField="$orderByField" :orderByDirection="$orderByDirection" :orderByFieldList="$orderByFieldList"/>
                        </th>
                        <th wire:click="changeOrder('categories.title')" style="cursor:pointer;">
                            <x-sort-arrows fieldName="Категория" :orderByField="$orderByField" :orderByDirection="$orderByDirection" :orderByFieldList="$orderByFieldList"/>
                        </th>
                        <th wire:click="changeOrder('posts.code')" style="cursor:pointer;">
                            <x-sort-arrows fieldName="Символьный код" :orderByField="$orderByField" :orderByDirection="$orderByDirection" :orderByFieldList="$orderByFieldList"/>
                        </th>
                        <th colspan="3" class="text-center">Действие</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($posts as $post)
                        <tr>
                            <td>{{ $post->id }}</td>
                            <td><a  href="{{ route('admin.post.edit', $post->id) }}">{{ $post->title }}</a></td>
                            <td>{{ $post->category_name }}</td>
                            <td>{{ $post->code }}</td>
                            <td class="text-center">
                                <a  href="{{ route('admin.post.show', $post->id) }}"><i class="far fa-eye">Смотреть</i></a>
                            </td>
                            <td class="text-center">
                                <a  href="{{ route('admin.post.edit', $post->id) }}" class="text-success"><i class="fas fa-pencil-alt">Редактировать</i></a>
                            </td>
                            <td class="text-center">
                                    <button wire:click="delete({{ $post->id }})" wire:confirm="Точно удалить пост?" class="border-0 bg-transparent">
                                        <i class="fas fa-trash text-danger" role="button"></i>
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

        {{ $posts->links(data:['scrollTo' => '#posts-list']) }}

    </div>
</div>

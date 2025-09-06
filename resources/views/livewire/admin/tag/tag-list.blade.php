<tbody>
    @foreach($tags as $tag)
        <tr wire:key="{{ $tag->id }}">
            <td>{{ $tag->id }}</td>
            <td>{{ $tag->title }}</td>
            <td>{{ $tag->code }}</td>
            <td class="text-center">
                <a  href="{{ route('admin.tag.show', $tag->id) }}"><i class="far fa-eye"></i></a>
            </td>
            <td class="text-center">
                <a  href="{{ route('admin.tag.edit', $tag->id) }}" class="text-success"><i class="fas fa-pencil-alt"></i></a>
            </td>
            <td class="text-center">
                    <div>
                    <button type="button" class="border-0 bg-transparent" wire:click="deleteTag({{$tag->id}})" wire:confirm="Вы действительно хотите удалить тег?">
                        <i class="fas fa-trash text-danger" role="button">Удалить</i>
                    </button>
                    </div>

            </td>
        </tr>
    @endforeach
</tbody>

<div class="row">



    <form wire:submit="save" enctype="multipart/form-data">
        @csrf <!-- не нужен в Livewire, но можно оставить -->

        <div class="form-group">
            @if($success)
                <div class="alert alert-success">{{ $success }}</div>
            @endif

        </div>

        <div class="form-group">
            <input type="text" class="form-control" wire:model="title" placeholder="Название поста">
            @error('title')
            <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <div wire:ignore>
                <textarea id="summernote" wire:model.defer="content"></textarea>
            </div>
            @error('content')
            <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group w-50">
            <label>Добавить превью</label>
            <div class="input-group">
                <div class="custom-file">
                    <input type="file" class="custom-file-input" wire:model="preview_image" id="preview_image">
                    <label class="custom-file-label">Выберите изображение</label>
                </div>
                <div class="input-group-append">
                    <span class="input-group-text">Загрузить</span>
                </div>
            </div>
            @error('preview_image')
            <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group w-50">
            <label>Добавить изображение</label>
            <div class="input-group">
                <div class="custom-file">
                    <input type="file" class="custom-file-input" wire:model="main_image" id="main_image">
                    <label class="custom-file-label">Выберите изображение</label>
                </div>
                <div class="input-group-append">
                    <span class="input-group-text">Загрузить</span>
                </div>
            </div>
            @error('main_image')
            <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group w-50">
            <label>Выберите категорию</label>
            <select class="form-control" wire:model="category_id">
                <option value="">-- Выберите категорию --</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->title }}</option>
                @endforeach
            </select>
            @error('category_id')
            <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group w-50">
            <label>Теги</label>
            <select id="tagSelect" class="form-control" multiple="multiple" wire:model="tag_ids">
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}">{{ $tag->title }}</option>
                @endforeach
            </select>
            @error('tag_ids')
            <div class="text-danger">{{ $message }}</div> @enderror
        </div>

{{--        <div class="form-group">--}}
{{--            <input type="checkbox" wire:model="translate" id="translate">--}}
{{--            <label for="translate">Сделать перевод</label>--}}
{{--        </div>--}}

        <div class="form-group">
            <button type="submit" class="btn btn-primary">Создать</button>
        </div>
    </form>
</div>


<script>
    document.addEventListener('livewire:init', () => {
        function init() {
            console.log('init');
        }

        // Инициализация при первом запуске
        init();

        // Повторная инициализация после каждого обновления Livewire
        Livewire.hook('message.processed', (message, component) => {
            init();
        });
    });

    document.addEventListener('livewire:init', () => {
        $('#summernote').summernote({
            height: 200,
            callbacks: {
                onChange: function (contents) {
                    @this.
                    set('content', contents);
                }
            }
        });
    });
</script>

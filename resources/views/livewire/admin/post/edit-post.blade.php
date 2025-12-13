<div class="row">
    <form wire:submit.prevent="update" enctype="multipart/form-data">
        <div class="form-group">
            <label>Название</label>
            <input type="text" class="form-control" wire:model="title" placeholder="Название поста">
            @error('title') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label>Символьный код</label>
            <input type="text" class="form-control" wire:model="code" placeholder="Символьный код">
            @error('code') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <label>Контент</label>
            <div wire:ignore>
                <textarea wire:model="content" id="summernote">{{ $content }}</textarea>
            </div>

            @error('content') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <!-- Превью изображение -->
        <div class="form-group w-50">
            <label>Добавить превью</label>
            @if($post->preview_image)
                <div class="w-25 mb-2">
                    <img class="w-50" src="{{ asset('storage/' . $post->preview_image) }}" alt="preview_image">
                </div>
            @endif
            <div class="input-group">
                <div class="custom-file">
                    <input type="file" class="custom-file-input" wire:model="preview_image" id="preview_image">
                    <label class="custom-file-label">Выберите изображение</label>
                </div>
                <div class="input-group-append">
                    <span class="input-group-text">Загрузить</span>
                </div>
            </div>
            @error('preview_image') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <!-- Основное изображение -->
        <div class="form-group w-50">
            <label>Добавить изображение</label>
            @if($post->main_image)
                <div class="w-25 mb-2">
                    <img class="w-50" src="{{ asset('storage/' . $post->main_image) }}" alt="main_image">
                </div>
            @endif
            <div class="input-group">
                <div class="custom-file">
                    <input type="file" class="custom-file-input" wire:model="main_image" id="main_image">
                    <label class="custom-file-label">Выберите изображение</label>
                </div>
                <div class="input-group-append">
                    <span class="input-group-text">Загрузить</span>
                </div>
            </div>
            @error('main_image') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <!-- Категория -->
        <div class="form-group w-50">
            <label>Выберите категорию</label>
            <select class="form-control" wire:model="category_id">
                <option value="">-- Выберите --</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->title }}</option>
                @endforeach
            </select>
            @error('category_id') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <!-- Теги -->
        <div class="form-group w-50">
            <label>Теги</label>
            <select class="form-control" multiple="multiple" data-placeholder="Выберите теги" style="width: 100%;" wire:model="tag_ids">
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}"
                            @if(in_array($tag->id, $tag_ids)) selected @endif>
                        {{ $tag->title }}
                    </option>
                @endforeach
            </select>
            <input type="hidden" wire:model="tag_ids" />
        </div>

        <!-- Опубликовано -->
        <div class="form-group">
            <label>
                <input type="checkbox" wire:model="published" value="1"> Опубликовано
            </label>
            @error('published') <div class="text-danger">{{ $message }}</div> @enderror
        </div>

        <div class="form-group">
            <button type="submit" class="btn btn-primary">Обновить</button>
        </div>

        <div
            x-data
            x-show="$wire.showSuccessMessage"
            x-init="$watch('$wire.showSuccessMessage', val => { if (val) setTimeout(() => $wire.showSuccessMessage = false, 3000) })"
            x-transition
            class="alert alert-success mt-3"
        >
            Пост успешно обновлён!
        </div>
    </form>
</div>

@push('scripts')
    <script>
        document.addEventListener('livewire:init', () => {

            console.log('livewire:init');

            $('#summernote').summernote({
                height: 200,
                callbacks: {
                    onChange: function (contents) {
                        @this.set('content', contents);
                    },
                    onImageUpload: function(files) {
                        const editor = $(this);
                        sendFile(files[0], editor);
                    }
                }
            });

            function sendFile(file, editor) {
                data = new FormData();
                data.append("file", file);
                $.ajax({
                    data: data,
                    type: "POST",
                    url: "/upload",
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    cache: false,
                    contentType: false,
                    processData: false,
                    success: function (url) {
                        $(editor).summernote('insertImage', url, '');
                    }
                });
            }

            // Инициализация Select2
            $('.select2').select2({
                placeholder: 'Выберите теги',
                allowClear: true
            }).on('change', function (e) {
                var data = $('.select2').select2('val');
                @this.set('tag_ids', data);
            });

            Livewire.on('postUpdated', () => {
                // JS-реакция на событие
                console.log('Пост обновлён!');
            });
        });

    </script>
@endpush

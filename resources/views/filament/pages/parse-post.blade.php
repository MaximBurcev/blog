<x-filament-panels::page>
    <form wire:submit="parse" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                Спарсить
            </x-filament::button>

            <x-filament::button
                tag="a"
                color="gray"
                :href="\App\Filament\Resources\PostResource::getUrl('index')"
            >
                К списку постов
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>

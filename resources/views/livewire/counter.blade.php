<div>
    <h1>{{ $count }}</h1>

    <button wire:click="increment">+</button>


    <button wire:click="decrement">-</button>

    <input type="number" wire:model.live="count">
</div>

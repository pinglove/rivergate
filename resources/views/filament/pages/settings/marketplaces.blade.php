<x-filament::page>
    <form wire:submit.prevent="save" class="max-w-xl space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">
            Save
        </x-filament::button>
    </form>
</x-filament::page>

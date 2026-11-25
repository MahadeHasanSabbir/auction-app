<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($this->canManage())
            <div class="flex justify-end">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Save changes') }}</x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>

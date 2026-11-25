@php
    $status = $this->status();
    $latest = $this->latest();
    $awaiting = $this->awaitsDocuments();

    $message = match ($status) {
        \App\Enums\KycStatus::Approved => __('The company is verified. Listing and withdrawals are open.'),
        \App\Enums\KycStatus::NotRequired => __('Nothing is needed from the company right now.'),
        \App\Enums\KycStatus::Submitted => __('The platform is checking the documents and will let you know.'),
        \App\Enums\KycStatus::Rejected => __('The documents were not accepted: :reason', ['reason' => $latest?->rejection_reason ?? '—']),
        default => $latest?->note ?? __('Upload the owner’s identity document and the trade licence. Until they are approved the company cannot list or withdraw.'),
    };
@endphp

<x-filament-panels::page>
    <x-filament::callout :color="$status->color()" icon="heroicon-o-shield-check" :heading="$status->label()" :description="$message" />

    @if ($latest && $latest->documents->isNotEmpty())
        <x-filament::section :heading="__('Documents sent')">
            <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($latest->documents as $document)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>{{ $document->type->label() }} <span class="text-xs text-gray-500">· {{ $document->original_name }}</span></span>
                        @can('view', $latest)
                            <x-filament::link :href="\App\Http\Controllers\KycDocumentController::link($document)" target="_blank">{{ __('View') }}</x-filament::link>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    @if ($awaiting)
        @if ($this->canManage())
            <form wire:submit="submit" class="space-y-6">
                {{ $this->form }}

                <div class="flex justify-end">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="submit">{{ __('Send for review') }}</x-filament::button>
                </div>
            </form>
        @else
            <x-filament::section>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Only the company owner can upload the documents.') }}</p>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

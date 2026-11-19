{{-- The newest current notice for sellers, at the top of the seller panel. Dismissed per browser. --}}
@php
    $notice = \App\Models\Notice::bannerFor(auth()->user());
@endphp

@if ($notice)
    <div x-data="{
            open: false,
            key: 'notice-dismissed-{{ $notice->id }}',
            init() { try { this.open = localStorage.getItem(this.key) === null } catch (e) { this.open = true } },
            dismiss() { this.open = false; try { localStorage.setItem(this.key, '1') } catch (e) {} },
         }"
         x-show="open" x-cloak class="mb-6">
        <x-filament::callout color="info" icon="heroicon-o-megaphone" :heading="$notice->title" :description="str($notice->body)->limit(300)->toString()">
            <x-slot name="controls">
                <x-filament::button tag="a" :href="route('notices')" size="sm" color="gray">{{ __('All notices') }}</x-filament::button>
                <x-filament::icon-button icon="heroicon-o-x-mark" color="gray" :label="__('Dismiss')"
                    x-on:click="dismiss()" />
            </x-slot>
        </x-filament::callout>
    </div>
@endif

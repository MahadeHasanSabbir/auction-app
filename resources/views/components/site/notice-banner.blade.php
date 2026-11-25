{{-- The newest current notice for this viewer. Dismissing hides that notice in this browser only. --}}
@php
    $notice = \App\Models\Notice::bannerFor(auth()->user());
@endphp

@if ($notice && ! request()->routeIs('notices'))
    <div x-data="{
            open: false,
            key: 'notice-dismissed-{{ $notice->id }}',
            init() { try { this.open = localStorage.getItem(this.key) === null } catch (e) { this.open = true } },
            dismiss() { this.open = false; try { localStorage.setItem(this.key, '1') } catch (e) {} },
         }"
         x-show="open" x-cloak
         class="border-b border-brand-200 bg-brand-50 dark:border-brand-900 dark:bg-brand-950">
        <div class="mx-auto flex max-w-7xl items-start gap-3 px-4 py-2.5 text-sm sm:px-6 lg:px-8">
            <x-app-icon name="bell" class="mt-0.5 size-4 shrink-0 text-brand-700 dark:text-brand-300" />
            <p class="min-w-0 flex-1 text-brand-900 dark:text-brand-100">
                <span class="font-semibold">{{ $notice->title }}</span>
                <span class="text-brand-800 dark:text-brand-200">— {{ str($notice->body)->limit(160) }}</span>
                <a href="{{ route('notices') }}" class="ms-1 font-medium underline underline-offset-2 hover:no-underline">{{ __('All notices') }}</a>
            </p>
            <button type="button" x-on:click="dismiss()"
                    class="shrink-0 rounded p-0.5 text-brand-700 hover:bg-brand-100 dark:text-brand-300 dark:hover:bg-brand-900"
                    aria-label="{{ __('Dismiss') }}">
                <x-app-icon name="x" class="size-4" />
            </button>
        </div>
    </div>
@endif

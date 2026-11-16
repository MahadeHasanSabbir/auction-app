{{--
    Toast messages. Shows the session "status" (success) and "error" flashes
    after a redirect, and anything a Livewire component dispatches as a
    "toast" browser event: $this->dispatch('toast', message: '…', type: 'error').
--}}
@php
    $initial = array_values(array_filter([
        session('status') ? ['message' => session('status'), 'type' => 'success'] : null,
        session('error') ? ['message' => session('error'), 'type' => 'error'] : null,
    ]));
@endphp

<div x-data="{
        toasts: [],
        add(toast) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, type: 'success', ...toast });
            setTimeout(() => this.remove(id), 6000);
        },
        remove(id) { this.toasts = this.toasts.filter((toast) => toast.id !== id); },
     }"
     x-init="@js($initial).forEach((toast) => add(toast))"
     @toast.window="add($event.detail)"
     class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-6 sm:bottom-6 sm:items-end"
     role="status" aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition.opacity.duration.300ms
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-white p-4 text-sm shadow-lg dark:bg-slate-900"
             :class="toast.type === 'error' ? 'border-red-300 dark:border-red-800' : 'border-brand-300 dark:border-brand-800'">
            <span class="mt-0.5 size-2 shrink-0 rounded-full" :class="toast.type === 'error' ? 'bg-red-500' : 'bg-brand-500'"></span>
            <p class="flex-1 text-slate-800 dark:text-slate-100" x-text="toast.message"></p>
            <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" @click="remove(toast.id)" aria-label="{{ __('Dismiss') }}">
                <x-app-icon name="x" class="size-4" />
            </button>
        </div>
    </template>
</div>

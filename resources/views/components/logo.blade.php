{{-- The wordmark. The name comes from APP_NAME until platform branding exists. --}}
<span {{ $attributes->class('inline-flex items-center gap-2 text-slate-900 dark:text-slate-50') }}>
    <span class="flex size-8 items-center justify-center rounded-md bg-brand-600 text-white">
        <x-app-icon name="gavel" class="size-5" />
    </span>
    <span class="font-display text-lg font-bold tracking-tight">{{ config('app.name') }}</span>
</span>

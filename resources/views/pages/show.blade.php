<x-site-layout :title="$page->title()" :description="$page->body()">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">{{ $page->title() }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ __('Last updated') }} <x-datetime :value="$page->updated_at" format="j F Y" />
        </p>

        {{-- Sanitised by UpdatePage when it was saved. --}}
        <div class="prose-page mt-8">{!! $page->body() !!}</div>
    </div>
</x-site-layout>

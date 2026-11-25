<div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
    <h1 class="font-display text-3xl font-semibold text-slate-900 dark:text-slate-50">{{ __('Notices') }}</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">{{ __('News and announcements from the marketplace.') }}</p>

    <div class="mt-8 space-y-4">
        @forelse ($notices as $notice)
            <x-card wire:key="notice-{{ $notice->id }}">
                <article>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-slate-50">{{ $notice->title }}</h2>
                        @if ($notice->audience !== \App\Enums\NoticeAudience::All)
                            <x-badge color="brand">{{ __('For :audience', ['audience' => mb_strtolower($notice->audience->label())]) }}</x-badge>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><x-datetime :value="$notice->published_at" format="j M Y" /></p>
                    <div class="mt-3 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $notice->body }}</div>
                </article>
            </x-card>
        @empty
            <x-empty-state :title="__('No notices right now')" :description="__('Announcements from the marketplace will appear here.')" />
        @endforelse

        {{ $notices->links() }}
    </div>
</div>

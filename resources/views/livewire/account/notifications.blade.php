<div>
    <x-account.shell :title="__('Notifications')" :description="__('What happened on your bids, orders and account.')">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav aria-label="{{ __('Filter') }}" class="flex gap-1">
                @foreach (['all' => __('All'), 'unread' => __('Unread')] as $key => $label)
                    <button type="button" wire:click="$set('filter', '{{ $key }}')" @if ($filter === $key) aria-current="page" @endif
                            @class([
                                'rounded-md px-3 py-1.5 text-sm font-medium',
                                'bg-brand-50 text-brand-800 dark:bg-brand-950 dark:text-brand-200' => $filter === $key,
                                'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800' => $filter !== $key,
                            ])>
                        {{ $label }}@if ($key === 'unread' && $unread > 0) ({{ $unread }})@endif
                    </button>
                @endforeach
            </nav>

            <div class="flex items-center gap-2">
                @if ($unread > 0)
                    <x-button type="button" variant="secondary" size="sm" wire:click="markAllRead">{{ __('Mark all as read') }}</x-button>
                @endif
                <x-button :href="route('profile.notifications')" variant="ghost" size="sm">{{ __('Settings') }}</x-button>
            </div>
        </div>

        @if ($notifications->isEmpty())
            <x-empty-state icon="bell" :title="$filter === 'unread' ? __('Nothing unread') : __('No notifications yet')"
                           :description="__('You will see outbid alerts, order updates and replies from sellers and support here.')" />
        @else
            <x-card :padding="false" class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($notifications as $notification)
                    <button type="button" wire:key="notification-{{ $notification->id }}" wire:click="open('{{ $notification->id }}')"
                            class="flex w-full items-start gap-3 px-5 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-amber-500' => $notification->read_at === null, 'bg-transparent' => $notification->read_at !== null])
                              @if ($notification->read_at === null) title="{{ __('Unread') }}" @endif></span>
                        <span class="min-w-0 flex-1">
                            <span @class(['block text-sm text-slate-900 dark:text-slate-100', 'font-semibold' => $notification->read_at === null, 'font-medium' => $notification->read_at !== null])>
                                {{ $notification->data['title'] ?? __('Notification') }}
                            </span>
                            @isset($notification->data['body'])
                                <span class="mt-0.5 block text-sm text-slate-600 dark:text-slate-400">{{ $notification->data['body'] }}</span>
                            @endisset
                            <span class="mt-1 block text-xs text-slate-500">{{ $notification->created_at->diffForHumans() }}</span>
                        </span>
                        <x-app-icon name="chevron-right" class="mt-1 size-4 shrink-0 text-slate-400" />
                    </button>
                @endforeach
            </x-card>

            {{ $notifications->links() }}
        @endif
    </x-account.shell>
</div>

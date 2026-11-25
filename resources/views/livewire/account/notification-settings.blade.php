<div>
    <x-account.shell :title="__('Notification settings')" :description="__('Choose what shows up in the bell and what is also sent to your email.')">
        @if ($pushKey)
            {{-- Web push: the browser has to agree on each device, so this switch is per browser. --}}
            <x-card class="mb-4" x-data="pushToggle(@js($pushKey), @js(route('push.store')))" x-show="supported" x-cloak>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="max-w-xl">
                        <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Notifications on this device') }}</h2>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Get the kinds ticked under “Phone / browser” as pop-ups, even when the site is closed. Each browser or phone is switched on separately.') }}</p>
                        <p class="mt-2 text-sm text-red-700 dark:text-red-400" x-show="denied">{{ __('Notifications are blocked for this site. Allow them in your browser’s site settings first.') }}</p>
                        <p class="mt-2 text-sm text-red-700 dark:text-red-400" x-show="failed">{{ __('That did not work. Please try again.') }}</p>
                    </div>
                    <button type="button" @click="toggle()" :disabled="busy || denied"
                            class="inline-flex items-center justify-center rounded-md border px-4 py-2 text-sm font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:opacity-60"
                            :class="enabled ? 'border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800' : 'border-transparent bg-brand-600 text-white hover:bg-brand-700'">
                        <span x-text="enabled ? @js(__('Turn off on this device')) : @js(__('Turn on for this device'))"></span>
                    </button>
                </div>
            </x-card>
        @endif

        <form wire:submit="save">
            <x-card :padding="false">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            <th scope="col" class="px-5 py-3 font-medium">{{ __('Kind') }}</th>
                            <th scope="col" class="px-3 py-3 text-center font-medium">{{ __('Bell') }}</th>
                            <th scope="col" class="px-3 py-3 text-center font-medium">{{ __('Email') }}</th>
                            @if ($pushKey)
                                <th scope="col" class="px-3 py-3 text-center font-medium">{{ __('Phone / browser') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($categories as $category)
                            <tr wire:key="category-{{ $category->value }}">
                                <th scope="row" class="px-5 py-4 text-left font-normal">
                                    <span class="block font-medium text-slate-900 dark:text-slate-100">{{ $category->label() }}</span>
                                    <span class="mt-0.5 block text-slate-600 dark:text-slate-400">{{ $category->description() }}</span>
                                </th>
                                @foreach (array_filter(['bell' => __('Bell'), 'mail' => __('Email'), 'push' => $pushKey ? __('Phone / browser') : null]) as $channel => $channelLabel)
                                    <td class="px-3 py-4 text-center">
                                        <input type="checkbox" wire:model="choices.{{ $category->value }}.{{ $channel }}"
                                               aria-label="{{ $category->label() }}: {{ $channelLabel }}"
                                               class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>

            <div class="mt-4 flex items-center gap-3">
                <x-button>{{ __('Save settings') }}</x-button>
                <x-button :href="route('notifications')" variant="ghost">{{ __('Back to notifications') }}</x-button>
            </div>
        </form>
    </x-account.shell>
</div>

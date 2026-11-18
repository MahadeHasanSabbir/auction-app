<div>
    <x-account.shell :title="__('Notification settings')" :description="__('Choose what shows up in the bell and what is also sent to your email.')">
        <form wire:submit="save">
            <x-card :padding="false">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            <th scope="col" class="px-5 py-3 font-medium">{{ __('Kind') }}</th>
                            <th scope="col" class="px-3 py-3 text-center font-medium">{{ __('Bell') }}</th>
                            <th scope="col" class="px-3 py-3 text-center font-medium">{{ __('Email') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($categories as $category)
                            <tr wire:key="category-{{ $category->value }}">
                                <th scope="row" class="px-5 py-4 text-left font-normal">
                                    <span class="block font-medium text-slate-900 dark:text-slate-100">{{ $category->label() }}</span>
                                    <span class="mt-0.5 block text-slate-600 dark:text-slate-400">{{ $category->description() }}</span>
                                </th>
                                @foreach (['bell' => __('Bell'), 'mail' => __('Email')] as $channel => $channelLabel)
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

<x-filament-panels::page>
    {{ $this->table }}

    @if ($this->invitations->isNotEmpty())
        <x-filament::section :heading="__('Waiting to join')" :description="__('Invitations that have not been accepted yet.')" icon="heroicon-o-envelope">
            <ul class="-my-3 divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($this->invitations as $invitation)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="invitation-{{ $invitation->id }}">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                <x-filament::icon icon="heroicon-o-envelope" class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $invitation->email }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Invited by :name · expires :date', ['name' => $invitation->inviter?->name ?? '—', 'date' => $invitation->expires_at->timezone($this->timezone())->format('j M Y')]) }}
                                </p>
                            </div>
                        </div>

                        @if ($this->canManage())
                            {{ ($this->revokeAction)(['invitation' => $invitation->id]) }}
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>

<div>
    <x-account.shell :title="__('API tokens')" :description="__('A token lets your own app or script use your account through the API, with the same rules as the website.')">
        @if ($newToken)
            <x-card class="border-brand-300 dark:border-brand-700" x-data="{ copied: false }">
                <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Your new token') }}</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ __('Copy it now. For your safety it is not shown again.') }}</p>
                <div class="mt-3 flex gap-2">
                    <input type="text" readonly value="{{ $newToken }}" class="form-control font-mono text-xs" aria-label="{{ __('Your new token') }}" x-on:focus="$el.select()">
                    <x-button type="button" variant="secondary" x-on:click="navigator.clipboard.writeText(@js($newToken)); copied = true">
                        <span x-show="! copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </x-button>
                </div>
                <div class="mt-3">
                    <x-button type="button" variant="ghost" wire:click="dismiss">{{ __('I have saved it') }}</x-button>
                </div>
            </x-card>
        @endif

        <x-card>
            <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Create a token') }}</h2>
            <form wire:submit="create" class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                <div>
                    <label for="token-name" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Name') }}</label>
                    <input id="token-name" type="text" wire:model="name" maxlength="60" placeholder="{{ __('For example: my phone app') }}" class="form-control mt-1.5" required>
                    @error('name') <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="token-access" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Access') }}</label>
                    <select id="token-access" wire:model="access" class="form-control mt-1.5">
                        <option value="read">{{ __('Read only') }}</option>
                        <option value="write">{{ __('Read and write') }}</option>
                    </select>
                </div>
                <x-button>{{ __('Create token') }}</x-button>
            </form>
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">
                {{ __('Tokens can bid, order and manage your listings, so keep them secret. Adding or withdrawing money is only possible on the website.') }}
            </p>
        </x-card>

        <x-card>
            <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Your tokens') }}</h2>
            @if ($tokens->isEmpty())
                <p class="mt-4 rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-400">{{ __('You have no tokens yet.') }}</p>
            @else
                <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($tokens as $token)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" wire:key="token-{{ $token->id }}">
                            <div>
                                <span class="font-medium text-slate-900 dark:text-slate-100">{{ $token->name }}</span>
                                <span class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                    {{ in_array('write', $token->abilities ?? [], true) ? __('Read and write') : __('Read only') }}
                                </span>
                                <span class="mt-0.5 block text-slate-500 dark:text-slate-400">
                                    {{ __('Last used') }}: @if ($token->last_used_at) <x-datetime :value="$token->last_used_at" /> @else {{ __('never') }} @endif
                                    @if ($token->expires_at) · {{ __('Expires') }}: <x-datetime :value="$token->expires_at" /> @endif
                                </span>
                            </div>
                            <x-button type="button" variant="secondary" wire:click="revoke({{ $token->id }})" wire:confirm="{{ __('Revoke this token? Apps using it stop working at once.') }}">{{ __('Revoke') }}</x-button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <p class="text-sm text-slate-600 dark:text-slate-400">
            <a class="font-medium text-brand-700 hover:underline dark:text-brand-300" href="{{ url('/docs/api') }}">{{ __('API documentation') }}</a>
        </p>
    </x-account.shell>
</div>

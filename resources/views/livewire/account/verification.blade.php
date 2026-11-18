<div>
    <x-account.shell :title="__('Verification')" :description="__('We check every buyer’s identity once before they bid, buy or withdraw.')">
        <x-card>
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-base font-semibold text-slate-900 dark:text-slate-50">{{ __('Status') }}</h2>
                <x-badge :color="$status->badge()">{{ $status->label() }}</x-badge>
            </div>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">
                @switch($status)
                    @case(\App\Enums\KycStatus::Approved)
                        {{ __('You are verified. Bidding, buying and withdrawals are open to you.') }}
                        @break
                    @case(\App\Enums\KycStatus::NotRequired)
                        {{ __('Nothing is needed from you right now.') }}
                        @break
                    @case(\App\Enums\KycStatus::Submitted)
                        {{ __('Thank you. We are checking your documents and will let you know.') }}
                        @break
                    @default
                        {{ __('Upload a clear photo or scan of your identity document. Until it is approved you can browse and add money, but not bid, buy or withdraw.') }}
                @endswitch
            </p>

            @if ($rejected && $latest?->rejection_reason)
                <div class="mt-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    <span class="font-medium">{{ __('Why it was not accepted:') }}</span> {{ $latest->rejection_reason }}
                </div>
            @elseif ($awaiting && $latest?->note)
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    <span class="font-medium">{{ __('From the platform:') }}</span> {{ $latest->note }}
                </div>
            @endif

            @if ($latest && $latest->documents->isNotEmpty())
                <div class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-800">
                    <h3 class="text-sm font-medium text-slate-900 dark:text-slate-50">{{ __('Documents you sent') }}</h3>
                    <ul class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        @foreach ($latest->documents as $document)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span class="text-slate-700 dark:text-slate-300">{{ $document->type->label() }} <span class="text-xs text-slate-500">· {{ $document->original_name }}</span></span>
                                <a href="{{ \App\Http\Controllers\KycDocumentController::link($document) }}" target="_blank" rel="noopener" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ __('View') }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-card>

        @if ($awaiting)
            <x-card>
                <form wire:submit="submit" class="space-y-5">
                    <h2 class="text-base font-semibold text-slate-900 dark:text-slate-50">{{ __('Your documents') }}</h2>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="kyc-id-type" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Identity document') }}</label>
                            <select id="kyc-id-type" wire:model="idType" class="form-control mt-1.5">
                                @foreach (\App\Livewire\Account\Verification::identityTypes() as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="kyc-id-file" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('File') }}</label>
                            <input id="kyc-id-file" type="file" wire:model="idFile" accept="image/jpeg,image/png,image/webp,application/pdf" class="form-control mt-1.5">
                            @error('idFile') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="kyc-extra-file" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Another document (optional)') }}</label>
                        <input id="kyc-extra-file" type="file" wire:model="extraFile" accept="image/jpeg,image/png,image/webp,application/pdf" class="form-control mt-1.5">
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ __('For example the back of your ID card.') }}</p>
                        @error('extraFile') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('JPG, PNG, WebP or PDF, up to :mb MB each. Only platform staff can see them.', ['mb' => $maxMb]) }}</p>

                    <div class="flex justify-end">
                        <x-button wire:loading.attr="disabled" wire:target="submit,idFile,extraFile">{{ __('Send for review') }}</x-button>
                    </div>
                </form>
            </x-card>
        @endif
    </x-account.shell>
</div>

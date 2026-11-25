<x-site-layout :title="__('Profile')">
    <x-account.shell :title="__('Profile')" :description="__('Your name, contact details and how times are shown to you.')">
        <x-card>
            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PATCH')

                {{-- Picture --}}
                <div class="flex flex-wrap items-center gap-5">
                    <x-avatar :user="$user" size="size-20" class="text-2xl" />

                    <div class="min-w-0 flex-1">
                        <label for="avatar" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Picture') }}</label>
                        <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp"
                               @error('avatar') aria-invalid="true" aria-describedby="avatar-error" @enderror
                               class="mt-1.5 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-slate-800 hover:file:bg-slate-200 dark:text-slate-400 dark:file:bg-slate-800 dark:file:text-slate-100 dark:hover:file:bg-slate-700">
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ __('JPG, PNG or WebP, up to 4 MB. It is cropped to a square.') }}</p>
                        @error('avatar')
                            <p id="avatar-error" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror

                        @if ($user->avatar_path)
                            <label class="mt-2 flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input type="checkbox" name="remove_avatar" value="1"
                                       class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900">
                                {{ __('Remove my picture') }}
                            </label>
                        @endif
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field :label="__('Name')" name="name" :value="$user->name" required autocomplete="name" />
                    <x-field :label="__('Phone')" name="phone" type="tel" :value="$user->phone" autocomplete="tel" />
                </div>

                <div>
                    <label for="address" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Address') }}</label>
                    <textarea id="address" name="address" rows="3" autocomplete="street-address"
                              @error('address') aria-invalid="true" aria-describedby="address-error" @enderror
                              class="form-control mt-1.5 @error('address') border-red-500 dark:border-red-500 @enderror">{{ old('address', $user->address) }}</textarea>
                    @error('address')
                        <p id="address-error" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:max-w-sm">
                    <label for="timezone" class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('Timezone') }}</label>
                    <select id="timezone" name="timezone" class="form-control mt-1.5">
                        <option value="">{{ __('Marketplace default (:timezone)', ['timezone' => config('auction.display_timezone')]) }}</option>
                        @foreach ($timezones as $timezone)
                            <option value="{{ $timezone }}" @selected(old('timezone', $user->timezone) === $timezone)>{{ str_replace('_', ' ', $timezone) }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Auction start and end times are shown in this timezone.') }}</p>
                    @error('timezone')
                        <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end border-t border-slate-100 pt-5 dark:border-slate-800">
                    <x-button>{{ __('Save profile') }}</x-button>
                </div>
            </form>
        </x-card>

        <x-card class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="font-sans text-base font-semibold tracking-normal text-slate-900 dark:text-slate-50">{{ __('Email and password') }}</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('You sign in as :email.', ['email' => $user->email]) }}
                </p>
            </div>
            <x-button :href="route('profile.security')" variant="secondary">{{ __('Sign-in and security') }}</x-button>
        </x-card>
    </x-account.shell>
</x-site-layout>

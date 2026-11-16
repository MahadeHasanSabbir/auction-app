<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    @php($user = Auth::user())

    <div class="py-6">
        <div class="max-w-3xl mx-auto bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">
            <p class="text-lg font-semibold">{{ __('Welcome, :name', ['name' => $user->name]) }}</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Account type: :role', ['role' => $user->role->label()]) }}
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                @if ($user->isPlatform())
                    <a href="{{ url('/admin') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                        {{ __('Open the admin panel') }}
                    </a>
                @elseif ($user->isSeller())
                    <a href="{{ url('/seller') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                        {{ __('Open the seller panel') }}
                    </a>
                @endif

                <a href="{{ route('auctions.index') }}" class="rounded-md border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700">
                    {{ __('Browse auctions') }}
                </a>
                <a href="{{ route('profile.edit') }}" class="rounded-md border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700">
                    {{ __('Edit profile') }}
                </a>
            </div>
        </div>
    </div>
</x-app-layout>

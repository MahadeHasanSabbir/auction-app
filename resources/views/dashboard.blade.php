<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ Auth::user()->name; }}
        </h2>
        <div class="max-w-8xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 grid gap-6 lg:grid-cols-3 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-center text-gray-900 dark:text-gray-100">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200 leading-tight">
                        {{ __("Number of sell") }}
                    </h3>
                    {{ Auth::user()->total_sell; }}
                </div>
                <div class="p-6 text-center text-gray-900 dark:text-gray-100">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200 leading-tight">
                        {{ __("Number of buy") }}
                    </h3>
                    {{ Auth::user()->total_buy; }}
                </div>
                <div class="p-6 text-center text-gray-900 dark:text-gray-100">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200 leading-tight">
                        {{ __("Number of bid") }}
                    </h3>
                    {{ Auth::user()->total_bid; }}
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-2">
        <div class="max-w-8xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    {{ __("ongoing auction") }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

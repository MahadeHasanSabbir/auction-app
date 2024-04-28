<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Create an auction
        </h2>
    </x-slot>
    <div class="py-2">
        <div class="max-w-8xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <form method="POST" action="{{ route('auction.store') }}">
                        @csrf

                        <!-- Name -->
                        <div>
                            <x-input-label for="name" :value="__('Name')" />
                            <x-text-input id="name" class="block mt-1 w-full" type="text"
                                            name="name" :value="old('name')"
                                            required autofocus autocomplete="name" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <!-- Start time -->
                        <div class="mt-4">
                            <x-input-label for="start_time" :value="__('Start_time')" />

                            <x-text-input id="start_time" class="block mt-1 w-full" type="date"
                                            name="start_time" :value="old('start_time')"
                                            required autocomplete="Start_time" />
                            <x-input-error :messages="$errors->get('start_time')" class="mt-2" />
                        </div>

                        <!-- End time -->
                        <div class="mt-4">
                            <x-input-label for="end_time" :value="{{ $product->starting_price }}" />

                            <x-text-input id="end_time" class="block mt-1 w-full" type="date"
                                            name="end_time" :value="old('end_time')"
                                            required autocomplete="End_time" />
                            <x-input-error :messages="$errors->get('end_time')" class="mt-2" />
                        </div>

                        <!-- Price -->
                        <div>
                            <x-input-label for="final_price" :value="__('Final price')" />
                            <x-text-input id="final_price" class="block mt-1 w-full" type="number"
                                            name="final_price" :value="old('name')"
                                            required autofocus autocomplete="final price" />
                            <x-input-error :messages="$errors->get('final_price')" class="mt-2" />
                        </div>

                        <div class="flex items-center justify-start mt-4">
                            <x-primary-button class="ms-4">
                                {{ __('Create Auction') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

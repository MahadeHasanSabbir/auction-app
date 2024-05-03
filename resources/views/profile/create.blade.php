<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight flex justify-center">
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

                            <x-text-input id="start_time1" class="block mt-1 w-full" type="time"
                                            name="start_time1" :value="old('start_time')"
                                            required autocomplete="Start_time" />
                            <x-input-error :messages="$errors->get('start_time')" class="mt-2" />
                        </div>

                        <!-- End time -->
                        <div class="mt-4">
                            <x-input-label for="end_time" :value="__('End time')" />

                            <x-text-input id="end_time" class="block mt-1 w-full" type="date"
                                            name="end_time" :value="old('end_time')"
                                            required autocomplete="End_time" />

                            <x-text-input id="end_time1" class="block mt-1 w-full" type="time"
                                            name="end_time1" :value="old('end_time')"
                                            required autocomplete="End_time" />
                            <x-input-error :messages="$errors->get('end_time')" class="mt-2" />
                        </div>

                        <!-- Price -->
                        <div class="mt-4">
                            <x-input-label for="final_price" :value="__('Price')" />
                            <x-text-input id="final_price" class="block mt-1 w-full" type="number"
                                            name="final_price" value="{{$product->starting_price}}"
                                            readonly autocomplete="final price" />
                            <x-input-error :messages="$errors->get('final_price')" class="mt-2" />
                        </div>

                        <!-- Host -->
                        <div>
                            <x-input-label for="host" :value="__('Host')" />
                            <x-text-input id="host" class="block mt-1 w-full" type="number"
                                            name="host" value="{{Auth::user()->id}}"
                                            readonly title="this field is not for edit" autocomplete="host" />
                            <x-input-error :messages="$errors->get('host')" class="mt-2" />
                        </div>

                        <!-- Product -->
                        <div>
                            <x-input-label for="product" :value="__('Product')" />
                            <x-text-input id="product" class="block mt-1 w-full" type="number"
                                            name="product" value="{{$product->id}}"
                                            readonly title="this field is not for edit" autocomplete="product" />
                            <x-input-error :messages="$errors->get('product')" class="mt-2" />
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

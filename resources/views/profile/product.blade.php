<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Add product for auction
        </h2>
    </x-slot>
    <div class="py-2">
        <div class="max-w-8xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <form method="POST" action="{{ route('product.store') }}">
                        @csrf

                        <!-- Name -->
                        <div>
                            <x-input-label for="name" :value="__('Name')" />

                            <x-text-input id="name" class="block mt-1 w-full" type="text"
                                            name="name" :value="old('name')"
                                            required autofocus autocomplete="name" />
                                            
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <!-- Category -->
                        <div class="mt-4">
                            <x-input-label for="category" :value="__('Category')" />

                            <x-text-input id="category" class="block mt-1 w-full" type="text"
                                            name="category" :value="old('category')"
                                            required autocomplete="Category" />

                            <x-input-error :messages="$errors->get('category')" class="mt-2" />
                        </div>

                        <!-- Description -->
                        <div class="mt-4">
                            <x-input-label for="description" :value="__('Product description')" />

                            <x-text-input id="description" class="block mt-1 w-full"
                                            type="text" name="description"
                                            required autocomplete="Short description of product" />

                            <x-input-error :messages="$errors->get('description')" class="mt-2" />
                        </div>

                        <!-- Starting price -->
                        <div class="mt-4">
                            <x-input-label for="starting_price" :value="__('Starting price')" />

                            <x-text-input id="starting_price" class="block mt-1 w-full"
                                            type="number" name="starting_price"
                                            required autocomplete="Starting price" />

                            <x-input-error :messages="$errors->get('starting_price')" class="mt-2" />
                        </div>

                        <div class="flex items-center justify-start mt-4">
                            <x-primary-button class="ms-4">
                                {{ __('Save Product') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
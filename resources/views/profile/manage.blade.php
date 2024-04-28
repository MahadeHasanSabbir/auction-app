<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Manage products for auction
        </h2>
    </x-slot>
    <div class="py-2">
        <div class="max-w-8xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                @foreach ($products as $product)
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        {{ "page in development!"}}
                    </div>
                    <div class="p-6 text-gray-900 dark:text-gray-100">
                        <a href="{{route('product.edit', $product->id)}}"> Edit </a>
                        <a href="{{route('product.delete', $product->id)}}"> Delete </a>
                        <a href="{{route('auction.create', $product->id)}}"> Add to auction </a>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>

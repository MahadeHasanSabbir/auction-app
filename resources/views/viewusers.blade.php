<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight flex justify-center">
            All the registered Users
        </h2>
    </x-slot>
    <div class="py-2">
        <div class="max-w-8xl mx-auto sm:px-6 lg:px-8">
            @if ($users->isEmpty())
                <p class="p-6 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg text-md flex justify-center">There is no user registered yet!</p>
            @endif
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg grid lg:grid-cols-3">
                @foreach ($users as $user)
                    
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>

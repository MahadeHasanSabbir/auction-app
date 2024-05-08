<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight flex justify-center">
            Manage auctions
        </h2>
    </x-slot>
    <div class="py-2">
        <div class="max-w-8xl mx-auto sm:px-6 lg:px-8">
            @if ($auctions->isEmpty())
                <p class="p-6 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg text-md flex justify-center">There is nothing to do here!</p>
            @endif
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg grid lg:grid-cols-3">
                @foreach ($auctions as $auction)
                    <div class="shadow">
                        <div class="p-6 m-4 text-gray-900 dark:text-gray-100">
                            <p>
                                <div class="text-lg">{{$auction->name}}</div>
                                <div class="text-sm">
                                    <span> Status:</span>
                                    @if ($auction->status == 0)
                                        {{ "On observation" }}
                                    @elseif ($auction->status == 1)
                                        {{ "Online" }}
                                    @else
                                        {{ "Request deny" }}
                                    @endif
                                </div>
                            </p>
                        </div>
                        @if (Auth::user()->role == 0 || $auction->host_id == Auth::user()->id)
                            <div class="p-6 text-gray-900 dark:text-gray-100 flex">
                                <a href="{{route('auction.show', $auction->id)}}">
                                    <x-primary-button class="ms-4">
                                        {{ __('View') }}
                                    </x-primary-button>
                                </a>
                                <form action="{{route('auction.destroy', $auction->id)}}" method="post">
                                    @csrf
                                    @method('DELETE')
                                    <x-danger-button class="ms-4">
                                        {{ __('Delete') }}
                                    </x-danger-button>
                                </form>
                            </div>
                        @else
                            <div class="p-4 text-gray-900 dark:text-gray-100 flex justify-start">
                                <div class="py-2 grid grid-cols-1">
                                    <a href="{{route('auction.show', $auction->id)}}">
                                        <x-primary-button>
                                            {{ __('View') }}
                                        </x-primary-button>
                                    </a>
                                    <a href="{{route('admin.accept', $auction->id)}}" class="mt-4">
                                        <x-primary-button>
                                            {{ __('Accept') }}
                                        </x-primary-button>
                                    </a>    
                                </div>
                                <form action="{{route('admin.deny', $auction->id)}}" method="POST" class="mx-auto mt-2 max-w-xl p-s-2 sm:mt-10">
                                    @csrf
                                    <div class="sm:col-span-2">
                                        <input type="text" name="massage" id="massage" required autocomplete="massage" class="block w-full rounded-md border-0 px-3.5 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" placeholder="Explain why you deny...">
                                    </div>
                                    <x-danger-button class="mt-2">
                                        {{ __('Deny') }}
                                    </x-danger-button>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>

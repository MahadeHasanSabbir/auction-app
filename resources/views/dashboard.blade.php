<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight flex justify-center">
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
        @if (session('status'))
            <div class="text-md w-full text-center">
                {{ session('status') }}
            </div>
        @endif
    </x-slot>

    <div class="py-2">
        <div class="max-w-8xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="text-2xl font-bold tracking-tight text-gray-900">Ongoing auction</h2>

                    <div class="mt-6 grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-4 xl:gap-x-8">
                        @php
                            $auctions = DB::table('auctions')->where('status', '1')
                                                            ->where('start_time', '<=', date('Y-m-d H:i:s'))
                                                            ->where('end_time', '>=', date('Y-m-d H:i:s'))
                                                            ->get();
                            if($auctions->isEmpty()){
                                echo "<div class'text-md mt-4''> Nothing is auctioning now!<br> Please wait for upcoming auction. </div>";
                            }
                        @endphp
                        @foreach ($auctions as $auction)
                            @php
                                $products = DB::table('products')->select('name', 'picture')->where('id', $auction->product_id)->get();
                                foreach ($products as $product) {
                                }
                            @endphp
                            <div class="group relative">
                                <div class="aspect-h-1 aspect-w-1 w-full overflow-hidden rounded-md bg-gray-200 lg:aspect-none group-hover:opacity-75 lg:h-80">
                                    <img src="{{ asset($product->picture) }}"
                                        alt="{{ $auction->name }}'s picture"
                                        class="h-full w-full object-cover object-center lg:h-full lg:w-full">
                                </div>
                                <div class="mt-4 flex justify-between">
                                    <div>
                                        <h3 class="text-md text-gray-700">
                                            <a href="{{ route('auction.show', $auction->id) }}">
                                                <span aria-hidden="true" class="absolute inset-0"></span>
                                                {{$auction->name}}
                                            </a>
                                        </h3>
                                        <p class="mt-1 text-sm text-gray-500">{{ $product->name }}</p>
                                    </div>
                                    <p class="text-sm font-medium text-gray-900">BDT {{ $auction->final_price }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

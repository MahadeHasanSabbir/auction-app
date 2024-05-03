<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>On going Auction | {{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased dark:bg-black dark:text-white/50">
    <div className="bg-gray-50 text-black/50 dark:bg-black dark:text-white/50">
        @include('layouts.navigation')
        <div
            class="relative min-h-screen flex flex-col items-center justify-center selection:bg-[#FF2D20] selection:text-white">
            <div class="relative w-full max-w-2xl px-6 lg:max-w-7xl">
                @php
                    $products = DB::table('products')->select('name','picture', 'starting_price')->where('id', $auction->product_id)->get();
                        foreach ($products as $product) {
                        }
                    $users = DB::table('users')->select('name')->where('id', $auction->host)->get();
                        foreach ($users as $user) {
                        }
                @endphp
                <main class="mt-6">
                    <div class="relative flex w-full items-center overflow-hidden bg-white px-4 pb-8 pt-14 shadow-2xl sm:px-6 sm:pt-8 md:p-6 lg:p-8">
                        <div class="grid w-full grid-cols-1 items-start gap-x-6 gap-y-8 sm:grid-cols-12 lg:gap-x-8">
                            <div class="aspect-h-1 aspect-w-3 overflow-hidden rounded-lg bg-gray-100 sm:col-span-4 lg:col-span-5">
                                <img src="{{ asset($product->picture) }}"
                                    alt="picture of {{ $auction->name }}'s auction"
                                    class="object-center" style="object-fit:contain;">
                            </div>
                            <div class="sm:col-span-8 lg:col-span-7">
                                <h2 class="text-2xl font-bold text-gray-900 sm:pr-12 text-center">{{ $auction->name }}</h2>

                                <section aria-labelledby="information-heading" class="mt-2">
                                    <h3 id="information-heading" class="sr-only">Product information</h3>
                                    <div class="flex justify-between">
                                        <p class="text-xl text-gray-900"> <b>Host:</b> {{$user->name}}</p>
                                        <p class="text-xl text-gray-900"> <b>Starting Price: </b> BDT{{$product->starting_price}}</p>
                                    </div>
                                    

                                    <!-- Reviews -->
                                    <div class="mt-6 flex justify-between">
                                        <p class="text-md"> <b> Number of bid: </b> {{$auction->no_of_bid}}</p>
                                        <p class="text-md"> <b> Last bidder: </b>
                                            @if ($auction->no_of_bid == 0)
                                                {{"N/A"}}
                                            @endif
                                             {{$auction->owner}}
                                        </p>
                                    </div>
                                </section>

                                <section aria-labelledby="options-heading" class="mt-6">
                                    <h3 id="options-heading" class="sr-only">bidding options</h3>
                                    @if ($auction->end_time <= date('Y-m-d H:i:s'))
                                        <p class="text-md flex justify-center">
                                            <b> New owner of {{$product->name}}: &nbsp</b>
                                            {{$auction->owner}}
                                        </p>
                                    @else
                                        <form
                                        @auth
                                            action="{{route('auction.update', $auction->id)}}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            @else
                                                action="{{route('login')}}">
                                        @endauth
                                            <div class="mt-4">
                                                <div class="flex items-center justify-between">
                                                    <h3 class="text-md font-medium text-gray-900"><b>Last Price: </b>BDT {{$auction->final_price}}</h3>
                                                    <input type="number" name="final_price" id="final_price" autocomplete="final_price" class="w-8xl rounded-md border-0 px-3.5 py-2 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6" placeholder="Offer your price here">
                                                </div>
                                            </div>
                                            <button type="submit" class="mt-6 flex w-full items-center justify-center rounded-md border border-transparent bg-indigo-600 px-8 py-3 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Bid price</button>
                                        </form>
                                    @endif
                                </section>
                            </div>
                        </div>
                    </div>
                </main>

                <footer class="py-16 text-center text-sm text-black dark:text-white/70">
                    Laravel v{{ Illuminate\Foundation\Application::VERSION }} (PHP v{{ PHP_VERSION }})
                </footer>
            </div>
        </div>
    </div>
</body>

</html>
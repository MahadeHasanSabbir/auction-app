<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title> Auctions | {{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased dark:bg-black dark:text-white/50">
    <div className="bg-gray-50 text-black/50 dark:bg-black dark:text-white/50">
        @include('layouts.navigation')
        <div class="relative min-h-screen flex flex-col items-center justify-center selection:bg-[#FF2D20] selection:text-white">
            <img src="{{asset('pexels-karolina-grabowska-4862892.jpg')}}" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover object-right md:object-center">
            <div class="hidden sm:absolute sm:-top-10 sm:right-1/2 sm:-z-10 sm:mr-10 sm:block sm:transform-gpu sm:blur-3xl" aria-hidden="true">
                <div class="aspect-[1097/845] w-[68.5625rem] bg-gradient-to-tr from-[#ff4694] to-[#776fff] opacity-20" style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)"></div>
            </div>
            <div class="absolute -top-52 left-1/2 -z-10 -translate-x-1/2 transform-gpu blur-3xl sm:top-[-28rem] sm:ml-16 sm:translate-x-0 sm:transform-gpu" aria-hidden="true">
                <div class="aspect-[1097/845] w-[68.5625rem] bg-gradient-to-tr from-[#ff4694] to-[#776fff] opacity-20" style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)"></div>
            </div>
            <div class="relative bg-blend-normal w-full max-w-2xl px-6 lg:max-w-7xl">

                <main class="mt-6">
                    <div class="mx-auto max-w-2xl px-4 py-16 sm:px-6 sm:py-24 lg:max-w-7xl lg:px-8">
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
                                    $products = DB::table('products')->select('name','picture')->where('id', $auction->product_id)->get();
                                    foreach ($products as $product) {
                                    }
                                @endphp
                                <div class="group relative bg-gray">
                                    <div class="aspect-h-1 aspect-w-1 w-full overflow-hidden rounded-md bg-gray-200 lg:aspect-none group-hover:opacity-75 lg:h-80">
                                        <img src="{{asset($product->picture)}}"
                                            alt="{{ $auction->name }}'s picture"
                                            class="h-full w-full object-cover object-center lg:h-full lg:w-full">
                                    </div>
                                    <div class="mt-4 flex justify-between">
                                        <div>
                                            <h3 class="text-md font-bold text-gray-700">
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
                    <div class="mx-auto max-w-2xl px-4 py-16 sm:px-6 sm:py-24 lg:max-w-7xl lg:px-8">
                        <h2 class="text-2xl font-bold tracking-tight text-gray-900">Upcoming auction</h2>

                        <div class="mt-6 grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-4 xl:gap-x-8">
                            @php
                                $auctions = DB::table('auctions')->where('status', '1')
                                                                ->where('start_time', '>=', date('Y-m-d H:i:s'))
                                                                ->get();
                            @endphp
                            @foreach ($auctions as $auction)
                                @php
                                    $products = DB::table('products')->select('name','picture')->where('id', $auction->product_id)->get();
                                    foreach ($products as $product) {
                                    }
                                @endphp
                                <div class="group relative">
                                    <div class="aspect-h-1 aspect-w-1 w-full overflow-hidden rounded-md bg-gray-200 lg:aspect-none group-hover:opacity-75 lg:h-80">
                                        <img src="{{asset($product->picture)}}"
                                            alt="{{ $auction->name }}'s picture"
                                            class="h-full w-full object-cover object-center lg:h-full lg:w-full">
                                    </div>
                                    <div class="mt-4 flex justify-between">
                                        <div>
                                            <h3 class="text-md font-bold text-gray-700">
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
                    <div class="mx-auto max-w-2xl px-4 py-16 sm:px-6 sm:py-24 lg:max-w-7xl lg:px-8">
                        <h2 class="text-2xl font-bold tracking-tight text-gray-900">Finished auction</h2>

                        <div class="mt-6 grid grid-cols-1 gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-4 xl:gap-x-8">
                            @php
                                $auctions = DB::table('auctions')->where('status', '1')
                                                                ->where('end_time', '<=', date('Y-m-d H:i:s'))
                                                                ->where('no_of_bid', '>', '0')
                                                                ->get();
                            @endphp
                            @foreach ($auctions as $auction)
                                @php
                                    $products = DB::table('products')->select('name','picture')->where('id', $auction->product_id)->get();
                                    foreach ($products as $product) {
                                    }
                                @endphp
                                <div class="group relative">
                                    <div class="aspect-h-1 aspect-w-1 w-full overflow-hidden rounded-md bg-gray-200 lg:aspect-none group-hover:opacity-75 lg:h-80">
                                        <img src="{{asset($product->picture)}}"
                                            alt="{{ $auction->name }}'s picture"
                                            class="h-full w-full object-cover object-center lg:h-full lg:w-full">
                                    </div>
                                    <div class="mt-4 flex justify-between">
                                        <div>
                                            <h3 class="text-md font-bold text-gray-700">
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
                </main>

                <footer class="py-16 text-center text-sm text-black dark:text-white/70">
                    Laravel v{{ Illuminate\Foundation\Application::VERSION }} (PHP v{{ PHP_VERSION }})
                </footer>
            </div>
        </div>
    </div>
</body>

</html>
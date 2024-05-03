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
        <div
            class="relative min-h-screen flex flex-col items-center justify-center selection:bg-[#FF2D20] selection:text-white">
            <div class="relative w-full max-w-2xl px-6 lg:max-w-7xl">

                <main class="mt-6">
                    <!-- Product info -->
                    <div class="mx-auto max-w-2xl px-4 pb-16 pt-10 sm:px-6 lg:grid lg:max-w-7xl lg:grid-cols-3 lg:grid-rows-[auto,auto,1fr] lg:gap-x-8 lg:px-8 lg:pb-24 lg:pt-16">
                        <div class="lg:col-span-2 lg:border-r lg:border-gray-200 lg:pr-8">
                            <h1 class="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">{{$product->name}}</h1>
                            <p class="text-xl tracking-tight text-gray-900">Price: BDT 
                                <b>{{$product->starting_price}}</b>
                            </p>
                        </div>

                        <!-- Options -->
                        <div class="mt-4 lg:row-span-3 lg:mt-0">
                            <h2 class="sr-only">Product information</h2>
                            <h3 class="sr-only">Category</h3>
                            <div class="flex items-center">
                                <p class="sr-only">{{$product->category}}</p>
                            </div>
                            <div class="aspect-h-4 aspect-w-3 hidden overflow-hidden rounded-lg lg:block">
                                <img src="{{asset($product->picture)}}"
                                    alt="{{ $product->name }}'s photo"
                                    class="h-full w-full object-cover object-center">
                            </div>
                        </div>

                        <div class="py-10 lg:col-span-2 lg:col-start-1 lg:border-r lg:border-gray-200 lg:pb-16 lg:pr-8 lg:pt-6">
                            <!-- Description and details -->
                            <div>
                                <h3 class="sr-only"> Product Description</h3>
                                <h3 class="text-xl tracking-tight text-gray-900"> Product Description</h3>

                                <div class="space-y-6">
                                    <p class="text-base text-gray-900">{{ $product->description }}</p>
                                </div>
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
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>About Us | {{ config('app.name', 'Laravel') }}</title>

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
                <img src="https://images.unsplash.com/photo-1521737604893-d14cc237f11d?ixlib=rb-4.0.3&ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&auto=format&fit=crop&crop=focalpoint&fp-y=.8&w=2830&h=1500&q=80&blend=111827&sat=-100&exp=15&blend-mode=multiply" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover object-right md:object-center">
                <div class="hidden sm:absolute sm:-top-10 sm:right-1/2 sm:-z-10 sm:mr-10 sm:block sm:transform-gpu sm:blur-3xl" aria-hidden="true">
                  <div class="aspect-[1097/845] w-[68.5625rem] bg-gradient-to-tr from-[#ff4694] to-[#776fff] opacity-20" style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)"></div>
                </div>
                <div class="absolute -top-52 left-1/2 -z-10 -translate-x-1/2 transform-gpu blur-3xl sm:top-[-28rem] sm:ml-16 sm:translate-x-0 sm:transform-gpu" aria-hidden="true">
                  <div class="aspect-[1097/845] w-[68.5625rem] bg-gradient-to-tr from-[#ff4694] to-[#776fff] opacity-20" style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)"></div>
                </div>
                <div class="relative isolate overflow-hidden w-full max-w-2xl px-6 lg:max-w-7xl">
                    
                    <main class="mt-4">
                        <div class="mx-auto max-w-7xl px-6 lg:px-8">
                            <div class="mx-auto max-w-3xl lg:mx-0 py-2">
                                <h2 class="text-4xl font-bold tracking-tight sm:text-6xl">About us</h2>
                                <p class="mt-6 text-lg leading-8 text-gray-800">We are a premier auction service provider, offering a seamless online auction platform complemented by physical offices in four key cities. With a dedicated team of 30+ full-time professionals and a network of part-time employees, we specialize in facilitating efficient and transparent auctions across various industries. Our robust web application provides users with a comprehensive and user-friendly experience, ensuring smooth transactions and optimal outcomes for both buyers and sellers.</p>
                            </div>
                            <div class="mx-auto mt-10 max-w-2xl lg:mx-0 lg:max-w-none py-2">
                                <div class="grid grid-cols-1 gap-x-8 gap-y-6 text-base font-semibold leading-7 sm:grid-cols-2 md:flex lg:gap-x-10 py-4">
                                    <a href="#">Open roles <span aria-hidden="true">&rarr;</span></a>
                                    <a href="#">Internship program <span aria-hidden="true">&rarr;</span></a>
                                    <a href="#">Our values <span aria-hidden="true">&rarr;</span></a>
                                    <a href="#">Meet our leadership <span aria-hidden="true">&rarr;</span></a>
                                </div>
                                <dl class="mt-16 grid grid-cols-1 gap-8 sm:mt-20 sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="flex flex-col-reverse">
                                        <dt class="text-base leading-7">Offices </dt>
                                        <dd class="text-2xl font-bold leading-9 tracking-tight">4</dd>
                                    </div>
                                    <div class="flex flex-col-reverse">
                                        <dt class="text-base leading-7">Full-time colleagues</dt>
                                        <dd class="text-2xl font-bold leading-9 tracking-tight">30+</dd>
                                    </div>
                                    <div class="flex flex-col-reverse">
                                        <dt class="text-base leading-7">Hours per week</dt>
                                        <dd class="text-2xl font-bold leading-9 tracking-tight">35</dd>
                                    </div>
                                    <div class="flex flex-col-reverse">
                                        <dt class="text-base leading-7">Paid time off</dt>
                                        <dd class="text-2xl font-bold leading-9 tracking-tight">Unlimited</dd>
                                    </div>
                                </dl>
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

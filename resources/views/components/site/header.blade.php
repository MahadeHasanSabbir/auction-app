@php
    $links = [
        ['route' => 'home', 'label' => __('Home'), 'active' => request()->routeIs('home')],
        ['route' => 'auctions.index', 'label' => __('Auctions'), 'active' => request()->routeIs('auctions.*')],
        ['route' => 'about', 'label' => __('About'), 'active' => request()->routeIs('about')],
    ];
@endphp

<header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-950/90">
    <div class="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">
        <a href="{{ route('home') }}" aria-label="{{ __('Home') }}">
            <x-logo />
        </a>

        <nav class="hidden items-center gap-1 md:flex" aria-label="{{ __('Main') }}">
            @foreach ($links as $link)
                <a href="{{ route($link['route']) }}" @if ($link['active']) aria-current="page" @endif
                   @class([
                       'rounded-md px-3 py-2 text-sm font-medium transition-colors',
                       'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-50' => $link['active'],
                       'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100' => ! $link['active'],
                   ])>
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-1 sm:gap-2">
            <button type="button" x-data @click="$store.theme.toggle()"
                    class="rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                    aria-label="{{ __('Switch between light and dark theme') }}">
                <x-app-icon name="moon" class="dark:hidden" />
                <x-app-icon name="sun" class="hidden dark:block" />
            </button>

            @if ($user)
                @if ($balance)
                    {{-- Links to the wallet once it exists (Phase 3). --}}
                    <span class="hidden items-center gap-2 rounded-md border border-slate-200 px-3 py-1.5 text-sm dark:border-slate-700 sm:inline-flex" title="{{ __('Available balance') }}">
                        <x-app-icon name="wallet" class="size-4 text-slate-500 dark:text-slate-400" />
                        <span class="sr-only">{{ __('Available balance') }}</span>
                        <x-money :amount="$balance" class="font-semibold text-slate-900 dark:text-slate-100" />
                    </span>
                @endif

                <x-dropdown width="w-80">
                    <x-slot:trigger>
                        <button type="button" class="relative rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                                aria-label="{{ trans_choice('{0} Notifications, none unread|[1,*] Notifications, :count unread', $unreadCount) }}">
                            <x-app-icon name="bell" />
                            @if ($unreadCount > 0)
                                <span class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold text-white">
                                    {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                                </span>
                            @endif
                        </button>
                    </x-slot:trigger>

                    <x-slot:content>
                        <p class="border-b border-slate-200 px-4 py-2 text-sm font-semibold text-slate-900 dark:border-slate-700 dark:text-slate-100">{{ __('Notifications') }}</p>

                        @forelse ($notifications as $notification)
                            <div class="px-4 py-3 text-sm">
                                <p class="font-medium text-slate-900 dark:text-slate-100">{{ $notification->data['title'] ?? __('Notification') }}</p>
                                @isset($notification->data['body'])
                                    <p class="mt-0.5 text-slate-600 dark:text-slate-400">{{ $notification->data['body'] }}</p>
                                @endisset
                                <p class="mt-1 text-xs text-slate-500">{{ $notification->created_at->diffForHumans() }}</p>
                            </div>
                        @empty
                            <p class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ __('You are all caught up.') }}</p>
                        @endforelse
                    </x-slot:content>
                </x-dropdown>

                <x-dropdown>
                    <x-slot:trigger>
                        <button type="button" class="flex items-center gap-2 rounded-md p-1 pr-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">
                            <x-avatar :user="$user" size="size-8" />
                            <span class="hidden max-w-32 truncate lg:inline">{{ $user->name }}</span>
                            <x-app-icon name="chevron-down" class="hidden size-4 text-slate-400 lg:block" />
                        </button>
                    </x-slot:trigger>

                    <x-slot:content>
                        <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                            <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $user->name }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                            @if ($balance)
                                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400 sm:hidden">
                                    {{ __('Available balance') }}: <x-money :amount="$balance" class="font-semibold text-slate-900 dark:text-slate-100" />
                                </p>
                            @endif
                        </div>

                        <x-dropdown-link :href="$workspace['url']">{{ $workspace['label'] }}</x-dropdown-link>
                        <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                        <x-dropdown-link :href="route('profile.security')">{{ __('Sign-in and security') }}</x-dropdown-link>

                        <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-200 dark:border-slate-700">
                            @csrf
                            <x-dropdown-link>{{ __('Sign out') }}</x-dropdown-link>
                        </form>
                    </x-slot:content>
                </x-dropdown>
            @else
                <x-button :href="route('login')" variant="ghost" size="sm" class="hidden sm:inline-flex">{{ __('Sign in') }}</x-button>
                <x-button :href="route('register')" size="sm">{{ __('Create account') }}</x-button>
            @endif

            <button type="button" @click="open = ! open" :aria-expanded="open" aria-controls="mobile-menu"
                    class="rounded-md p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 md:hidden"
                    aria-label="{{ __('Menu') }}">
                <x-app-icon name="menu" x-show="! open" />
                <x-app-icon name="x" x-show="open" x-cloak />
            </button>
        </div>
    </div>

    <nav id="mobile-menu" x-show="open" x-cloak x-transition.opacity class="border-t border-slate-200 px-4 py-3 dark:border-slate-800 md:hidden" aria-label="{{ __('Main') }}">
        @foreach ($links as $link)
            <a href="{{ route($link['route']) }}" @if ($link['active']) aria-current="page" @endif
               @class([
                   'block rounded-md px-3 py-2 text-base font-medium',
                   'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-50' => $link['active'],
                   'text-slate-600 dark:text-slate-300' => ! $link['active'],
               ])>
                {{ $link['label'] }}
            </a>
        @endforeach

        @guest
            <a href="{{ route('login') }}" class="block rounded-md px-3 py-2 text-base font-medium text-slate-600 dark:text-slate-300 sm:hidden">{{ __('Sign in') }}</a>
        @endguest
    </nav>
</header>

{{--
    The site sets only cookies it needs to work (session, form protection, a
    trusted browser, the language), and no tracking, so this informs rather
    than asks. "OK" hides it in this browser.
--}}
<div x-data="{
        open: false,
        init() { try { this.open = localStorage.getItem('cookie-notice-ok') === null } catch (e) { this.open = false } },
        ok() { this.open = false; try { localStorage.setItem('cookie-notice-ok', '1') } catch (e) {} },
     }"
     x-show="open" x-cloak
     role="region" aria-label="{{ __('Cookies') }}"
     class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-3 text-sm sm:px-6 lg:px-8">
        <p class="min-w-0 flex-1 text-slate-700 dark:text-slate-300">
            {{ __('We use only the cookies the site needs to work: to keep you signed in, protect forms and remember your language. No advertising or tracking cookies.') }}
            @if (\Illuminate\Support\Facades\Route::has('pages.privacy'))
                <a href="{{ route('pages.privacy') }}" class="font-medium text-brand-700 underline underline-offset-2 hover:no-underline dark:text-brand-300">{{ __('Privacy policy') }}</a>
            @endif
        </p>
        <x-button size="sm" type="button" x-on:click="ok()">{{ __('OK') }}</x-button>
    </div>
</div>

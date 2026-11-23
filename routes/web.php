<?php

use App\Enums\SitePage;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KycDocumentController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SitemapController;
use App\Livewire\Account\ApiTokens;
use App\Livewire\Account\Bids;
use App\Livewire\Account\Invoices as AccountInvoices;
use App\Livewire\Account\Notifications;
use App\Livewire\Account\NotificationSettings;
use App\Livewire\Account\Orders;
use App\Livewire\Account\Overview;
use App\Livewire\Account\Purchases;
use App\Livewire\Account\Referrals;
use App\Livewire\Account\SavedSearches;
use App\Livewire\Account\Verification;
use App\Livewire\Auctions\Index as AuctionIndex;
use App\Livewire\Auctions\Show as AuctionShow;
use App\Livewire\Contact;
use App\Livewire\Home;
use App\Livewire\Notices;
use App\Livewire\Orders\Show as OrderShow;
use App\Livewire\Sellers\Show as SellerShow;
use App\Livewire\Support\Index as SupportIndex;
use App\Livewire\Support\Show as SupportShow;
use App\Livewire\Wallet\Show as WalletShow;
use App\Livewire\Wallet\Withdraw;
use Illuminate\Support\Facades\Route;

/*
| The public site and the signed-in account pages. Selling happens in the
| seller panel (/seller) and platform administration in the admin panel
| (/admin); both are Filament panels registered by their own providers.
*/

Route::get('/', Home::class)->name('home');
Route::get('/about', [PageController::class, 'about'])->name('about');
Route::get('/terms', [PageController::class, 'show'])->defaults('page', SitePage::Terms->value)->name('pages.terms');
Route::get('/privacy', [PageController::class, 'show'])->defaults('page', SitePage::Privacy->value)->name('pages.privacy');
Route::get('/faq', [PageController::class, 'show'])->defaults('page', SitePage::Faq->value)->name('pages.faq');
Route::post('/locale', LocaleController::class)->middleware('throttle:30,1')->name('locale');
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', fn () => response(implode("\n", [
    'User-agent: *',
    // Panels, account pages and Livewire requests are not for search engines.
    ...array_map(fn (string $path) => "Disallow: /{$path}", ['admin', 'seller', 'livewire', 'dashboard', 'wallet', 'my-bids', 'orders', 'push', 'purchases', 'saved-searches', 'referrals', 'invoices', 'verification', 'notifications', 'support', 'profile', 'login', 'register']),
    'Sitemap: '.route('sitemap'),
])."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']))->name('robots');
Route::get('/contact', Contact::class)->name('contact');
Route::get('/auctions', AuctionIndex::class)->name('auctions.index');
// The slug is only for people and search engines (Auction::url()); the id finds the auction.
Route::get('/auctions/{auction}/{slug?}', AuctionShow::class)->where('slug', '[a-z0-9-]+')->name('auctions.show');
Route::get('/sellers/{company:slug}', SellerShow::class)->name('sellers.show');
Route::get('/notices', Notices::class)->name('notices');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Overview::class)->name('dashboard');
    Route::get('/wallet', WalletShow::class)->name('wallet');
    Route::get('/my-bids', Bids::class)->name('bids');
    Route::get('/orders', Orders::class)->name('orders');
    Route::get('/orders/{order}', OrderShow::class)->name('orders.show');
    Route::get('/purchases', Purchases::class)->name('purchases');
    Route::get('/saved-searches', SavedSearches::class)->name('saved-searches');
    Route::get('/referrals', Referrals::class)->name('referrals');
    Route::get('/invoices', AccountInvoices::class)->name('invoices');
    Route::get('/verification', Verification::class)->name('verification');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'show'])->name('invoices.pdf');
    Route::get('/kyc-documents/{document}', [KycDocumentController::class, 'show'])->middleware('signed')->name('kyc.document');
    Route::get('/notifications', Notifications::class)->name('notifications');
    Route::get('/support', SupportIndex::class)->name('support.index');
    Route::get('/support/{conversation}', SupportShow::class)->name('support.show');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('/profile/notifications', NotificationSettings::class)->name('profile.notifications');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:20,1')->name('push.store');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1')->name('push.destroy');

    // Email, password, trusted devices, closing the account: these ask for
    // an emailed code first.
    Route::middleware('otp.confirmed')->group(function () {
        Route::get('/profile/security', [SecurityController::class, 'show'])->name('profile.security');
        Route::patch('/profile/email', [SecurityController::class, 'updateEmail'])->name('profile.email');
        Route::delete('/profile/devices', [SecurityController::class, 'forgetDevices'])->name('profile.devices');
        Route::delete('/profile', [SecurityController::class, 'destroy'])->name('profile.destroy');

        Route::get('/wallet/withdraw', Withdraw::class)->name('wallet.withdraw');
        Route::get('/profile/api-tokens', ApiTokens::class)->name('profile.api-tokens');
    });
});

require __DIR__.'/auth.php';

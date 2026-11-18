<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecurityController;
use App\Livewire\Account\Bids;
use App\Livewire\Account\Orders;
use App\Livewire\Account\Overview;
use App\Livewire\Auctions\Index as AuctionIndex;
use App\Livewire\Auctions\Show as AuctionShow;
use App\Livewire\Home;
use App\Livewire\Orders\Show as OrderShow;
use App\Livewire\Sellers\Show as SellerShow;
use App\Livewire\Wallet\Show as WalletShow;
use App\Livewire\Wallet\Withdraw;
use Illuminate\Support\Facades\Route;

/*
| The public site and the signed-in account pages. Selling happens in the
| seller panel (/seller) and platform administration in the admin panel
| (/admin); both are Filament panels registered by their own providers.
*/

Route::get('/', Home::class)->name('home');
Route::view('/about', 'about')->name('about');
Route::get('/auctions', AuctionIndex::class)->name('auctions.index');
Route::get('/auctions/{auction}', AuctionShow::class)->name('auctions.show');
Route::get('/sellers/{company:slug}', SellerShow::class)->name('sellers.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Overview::class)->name('dashboard');
    Route::get('/wallet', WalletShow::class)->name('wallet');
    Route::get('/my-bids', Bids::class)->name('bids');
    Route::get('/orders', Orders::class)->name('orders');
    Route::get('/orders/{order}', OrderShow::class)->name('orders.show');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Email, password, trusted devices, closing the account: these ask for
    // an emailed code first.
    Route::middleware('otp.confirmed')->group(function () {
        Route::get('/profile/security', [SecurityController::class, 'show'])->name('profile.security');
        Route::patch('/profile/email', [SecurityController::class, 'updateEmail'])->name('profile.email');
        Route::delete('/profile/devices', [SecurityController::class, 'forgetDevices'])->name('profile.devices');
        Route::delete('/profile', [SecurityController::class, 'destroy'])->name('profile.destroy');

        Route::get('/wallet/withdraw', Withdraw::class)->name('wallet.withdraw');
    });
});

require __DIR__.'/auth.php';

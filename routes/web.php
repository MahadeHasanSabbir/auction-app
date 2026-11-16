<?php

use App\Http\Controllers\AuctionListController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
| The public site and the signed-in account pages. Selling happens in the
| seller panel (/seller) and platform administration in the admin panel
| (/admin); both are Filament panels registered by their own providers.
*/

Route::view('/', 'welcome')->name('home');
Route::view('/about', 'aboutus')->name('about');
Route::get('/auctions', AuctionListController::class)->name('auctions.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

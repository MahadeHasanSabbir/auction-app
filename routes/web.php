<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuctionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AuctionViewController;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/about', function () {
    return view('aboutus');
})->name('about');

Route::resource('product', ProductController::class)->middleware(['auth', 'verified'])->except('show');
Route::resource('product', ProductController::class)->only('show');

Route::resource('auction', AuctionController::class)->middleware(['auth', 'verified'])->except('create', 'show', 'edit');
Route::resource('product.auction', AuctionController::class)->middleware(['auth', 'verified'])->only('create');
Route::resource('auction', AuctionController::class)->only('show');

Route::get('/auctions', function(){
    return view('auction');
})->name('auction.view');

/*
Route::post('/products', function () {
    return view('profile.product');
})->middleware(['auth', 'verified'])->name('products');
 */

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth', 'verified', 'admin')->group(function () {
    Route::get('/admin', [AdminController::class, 'index'])->name('admin.index');
    Route::get('/requests', [AdminController::class, 'auction'])->name('admin.auction');
    Route::get('/request/{id}', [AdminController::class, 'accept'])->name('admin.accept');
    Route::get('/deny/{id}', [AdminController::class, 'deny'])->name('admin.deny');
    Route::get('/users', [AdminController::class, 'users'])->name('admin.users');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

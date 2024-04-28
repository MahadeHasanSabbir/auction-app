<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuctionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/about', function () {
    return view('aboutus');
})->name('about');

Route::resource('product', ProductController::class)->middleware(['auth', 'verified']);

Route::resource('auction', AuctionController::class)->middleware(['auth', 'verified']);
/*
Route::get('/products', function () {
    return view('profile.product');
})->middleware(['auth', 'verified'])->name('product.manage');

Route::post('/products', function () {
    return view('profile.product');
})->middleware(['auth', 'verified'])->name('products');
 */

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

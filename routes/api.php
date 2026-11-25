<?php

use App\Http\Controllers\Api\V1\AuctionController;
use App\Http\Controllers\Api\V1\BiddingController;
use App\Http\Controllers\Api\V1\CatalogueController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\Seller;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

/*
| The REST API, version 1 (served under /api/v1, see bootstrap/app.php).
| Every endpoint calls the same actions and policies as the website and the
| panels. Tokens are personal access tokens (Sanctum) made on /profile/api-tokens;
| a read-only token may only use GET. Money moves that need a fresh emailed code
| (deposits, withdrawals) are not part of the API.
*/

Route::middleware('throttle:api')->group(function () {
    // Public.
    Route::get('/auctions', [AuctionController::class, 'index']);
    Route::get('/auctions/{auction}', [AuctionController::class, 'show'])->whereNumber('auction');
    Route::get('/auctions/{auction}/bids', [AuctionController::class, 'bids'])->whereNumber('auction');
    Route::get('/categories', [CatalogueController::class, 'categories']);
    Route::get('/sellers/{company:slug}', [CatalogueController::class, 'seller']);

    // Signed in with a token.
    Route::middleware(['auth:sanctum', 'api.log', 'api.account'])->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::delete('/me/token', [MeController::class, 'revoke'])->name('api.token.revoke');

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/statement', [WalletController::class, 'statement']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);

        // Buyers.
        Route::get('/my/auctions', [BiddingController::class, 'mine']);
        Route::post('/auctions/{auction}/bids', [BiddingController::class, 'bid'])->whereNumber('auction');
        Route::post('/auctions/{auction}/buy-now', [BiddingController::class, 'buyNow'])->whereNumber('auction');
        Route::post('/auctions/{auction}/watch', [BiddingController::class, 'watch'])->whereNumber('auction');

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::patch('/orders/{order}/delivery-address', [OrderController::class, 'address']);
        Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
        Route::post('/orders/{order}/dispute', [OrderController::class, 'dispute']);
        Route::post('/orders/{order}/review', [OrderController::class, 'review']);
        Route::get('/orders/{order}/messages', [OrderController::class, 'messages']);
        Route::post('/orders/{order}/messages', [OrderController::class, 'send']);

        // Seller companies.
        Route::prefix('seller')->group(function () {
            Route::apiResource('products', Seller\ProductController::class);
            Route::post('products/{product}', [Seller\ProductController::class, 'update'])->whereNumber('product');

            Route::get('auctions', [Seller\AuctionController::class, 'index']);
            Route::post('auctions', [Seller\AuctionController::class, 'store']);
            Route::get('auctions/{auction}', [Seller\AuctionController::class, 'show'])->whereNumber('auction');
            Route::put('auctions/{auction}', [Seller\AuctionController::class, 'update'])->whereNumber('auction');
            Route::post('auctions/{auction}/submit', [Seller\AuctionController::class, 'submit'])->whereNumber('auction');
            Route::post('auctions/{auction}/withdraw', [Seller\AuctionController::class, 'withdraw'])->whereNumber('auction');
            Route::post('auctions/{auction}/cancel', [Seller\AuctionController::class, 'cancel'])->whereNumber('auction');

            Route::get('orders', [Seller\OrderController::class, 'index']);
            Route::post('orders/{order}/ship', [Seller\OrderController::class, 'ship']);
            Route::post('orders/{order}/cancel', [Seller\OrderController::class, 'cancel']);
        });
    });
});

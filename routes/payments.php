<?php

use App\Http\Controllers\Payments\PaymentCallbackController;
use Illuminate\Support\Facades\Route;

/*
| Payment gateway callbacks, loaded from bootstrap/app.php without the web
| middleware: no session, no cookies, no CSRF check. Every request is
| confirmed with the gateway before anything is credited.
*/

Route::match(['get', 'post'], '/payments/{gateway}/return', [PaymentCallbackController::class, 'return'])
    ->where('gateway', '[a-z]+')
    ->name('payments.return');

Route::post('/payments/{gateway}/notify', [PaymentCallbackController::class, 'notify'])
    ->where('gateway', '[a-z]+')
    ->name('payments.notify');

<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginCodeController;
use App\Http\Controllers\Auth\OtpConfirmationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

// A team invitation from an email. Open to anyone with the link; the page
// itself says what to do when someone is already signed in.
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationController::class, 'store'])->middleware(['guest', 'throttle:10,1'])->name('invitations.store');

Route::middleware('guest')->group(function () {
    // Sign-up: a buyer account, or a seller company with its owner.
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');

    // Sign-in, step one: email and password.
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    // LoginRequest limits per address and IP; this caps one IP across many addresses.
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:30,1');

    // Sign-in, step two: the emailed code.
    Route::get('login/code', [LoginCodeController::class, 'create'])->name('login.code');
    Route::post('login/code', [LoginCodeController::class, 'store'])->middleware('throttle:10,1');
    Route::post('login/code/resend', [LoginCodeController::class, 'resend'])->middleware('throttle:6,1')->name('login.code.resend');

    Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])->middleware('throttle:20,1')->name('google.redirect');
    Route::get('auth/google/callback', [GoogleController::class, 'callback'])->middleware('throttle:20,1')->name('google.callback');

    // Forgotten password: the address, then the emailed code with a new password.
    Route::get('forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.store');
    Route::post('reset-password/resend', [PasswordResetController::class, 'resend'])->middleware('throttle:6,1')->name('password.resend');
});

Route::middleware('auth')->group(function () {
    // Confirming the email address with a code.
    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('verify-email', [EmailVerificationController::class, 'verify'])->middleware('throttle:10,1')->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1')->name('verification.send');

    // A fresh code before a sensitive account page opens.
    Route::get('confirm-code', [OtpConfirmationController::class, 'show'])->name('otp.confirm');
    Route::post('confirm-code', [OtpConfirmationController::class, 'store'])->middleware('throttle:10,1');
    Route::post('confirm-code/resend', [OtpConfirmationController::class, 'resend'])->middleware('throttle:6,1')->name('otp.confirm.resend');

    Route::put('password', [PasswordController::class, 'update'])->middleware('otp.confirmed')->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

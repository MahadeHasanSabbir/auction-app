<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('log:clear', function () {
    exec('echo "" > '.storage_path('logs/laravel.log'));

    $this->comment('Log has been clear!');
})->describe('Clear log file');

// Logs a critical error when the books do not add up.
Schedule::command('ledger:verify')->dailyAt('03:00')->withoutOverlapping();

// Renews plans that are due, retries unpaid ones and expires lapsed ones.
Schedule::command('subscriptions:renew')->hourly()->withoutOverlapping();

// Opens scheduled auctions and queues the closing of ended ones.
Schedule::command('auctions:sweep')->everyFiveSeconds()->withoutOverlapping();

<?php

use App\Models\ApiRequest;
use App\Models\IdempotencyKey;
use App\Models\LoginEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('log:clear', function () {
    exec('echo "" > '.storage_path('logs/laravel.log'));

    $this->comment('Log has been clear!');
})->describe('Clear log file');

// Drops sign-in history, API request logs and old replay keys past their retention
// (auction.trust.login_history_days, auction.api.log_days, auction.retention.*).
Schedule::command('model:prune', ['--model' => [LoginEvent::class, ApiRequest::class, IdempotencyKey::class]])->daily();

// Read notifications older than auction.retention.read_notifications_days.
Schedule::call(fn () => DB::table('notifications')->whereNotNull('read_at')
    ->where('created_at', '<', now()->subDays((int) config('auction.retention.read_notifications_days')))->delete())
    ->daily()->name('notifications:prune');

// Database dump and uploaded files (auction.backup); copy the folder off the server too.
Schedule::command('backup:run')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Logs a critical error when the books do not add up.
Schedule::command('ledger:verify')->dailyAt('03:00')->withoutOverlapping();

// Renews plans that are due, retries unpaid ones and expires lapsed ones.
Schedule::command('subscriptions:renew')->hourly()->withoutOverlapping();

// Cancels orders not shipped in time and releases escrow of delivered ones.
Schedule::command('orders:sweep')->everyMinute()->withoutOverlapping();

// Opens scheduled auctions and queues the closing of ended ones.
Schedule::command('auctions:sweep')->everyFiveSeconds()->withoutOverlapping();

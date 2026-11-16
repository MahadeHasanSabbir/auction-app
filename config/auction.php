<?php

/*
|--------------------------------------------------------------------------
| Marketplace defaults
|--------------------------------------------------------------------------
|
| Install-time defaults for the marketplace. Values the platform staff can
| change at runtime (biddable share, anti-sniping, escrow timings) are read
| from the settings table first and fall back to what is defined here.
|
| Money is always stored as integers in minor units (paisa, cents) and
| rates as basis points (1% = 100 bps). There are no floats anywhere.
|
*/

return [

    // One currency for the whole marketplace. Changing it after money has
    // moved is not supported.
    'currency' => env('AUCTION_CURRENCY', 'BDT'),

    // Number of minor-unit digits of that currency (BDT, USD, EUR: 2).
    'currency_decimals' => (int) env('AUCTION_CURRENCY_DECIMALS', 2),

    // The system owner account created by `php artisan db:seed`. Outside
    // production the password falls back to "admin"; in production seeding
    // refuses to run until AUCTION_OWNER_PASSWORD is set.
    'owner' => [
        'name' => env('AUCTION_OWNER_NAME', 'System Owner'),
        'email' => env('AUCTION_OWNER_EMAIL', 'admin@auction.com'),
        // An empty value in .env counts as "not set".
        'password' => env('AUCTION_OWNER_PASSWORD') ?: null,
    ],

    // Times are stored in UTC and shown in the viewer's own timezone when
    // the account has one, otherwise in this one.
    'display_timezone' => env('AUCTION_DISPLAY_TIMEZONE', 'Asia/Dhaka'),

    // Emailed one-time codes: sign-up verification, the login second step
    // and the confirmation asked before sensitive account changes.
    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        // Wrong guesses allowed against one code before it is dead.
        'max_attempts' => 5,
        // Minimum wait between two codes, and the most codes per hour.
        'resend_seconds' => 60,
        'max_per_hour' => 5,
        // How long a confirmed code keeps the sensitive pages unlocked.
        'confirm_minutes' => 15,
        // How long a browser marked "trust this device" skips the login code.
        'trusted_device_days' => 30,
    ],

    'bidding' => [
        // Share of a buyer's available balance that may be committed to bids.
        'biddable_share_bps' => 8000,

        // A bid placed within this many seconds of the end extends the auction.
        'anti_snipe_window_seconds' => 120,
        'anti_snipe_extend_seconds' => 120,
        'anti_snipe_max_extensions' => 20,
    ],

    'orders' => [
        // The company must ship within this many days or the order is cancelled.
        'ship_by_days' => 5,

        // Escrow is released automatically this many days after shipment.
        'auto_release_days' => 7,
    ],

];

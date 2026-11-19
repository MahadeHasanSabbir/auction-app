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

    // Languages of the public site (switcher in the header; lang/bn.json).
    // The admin and seller panels and emails stay in app.locale.
    'locales' => [
        'en' => 'English',
        'bn' => 'বাংলা',
    ],

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

    'payments' => [
        // Gateways offered to payers, comma separated: fake, sslcommerz,
        // stripe. The fake one pays at once and is never offered in
        // production.
        'gateways' => array_filter(explode(',', (string) env('PAYMENT_GATEWAYS', 'fake'))),

        // Limits on one deposit, in minor units (10_000 is BDT 100.00).
        'deposit_min' => (int) env('AUCTION_DEPOSIT_MIN', 10_000),
        'deposit_max' => (int) env('AUCTION_DEPOSIT_MAX', 100_000_000),

        // Smallest withdrawal, in minor units (50_000 is BDT 500.00).
        'withdrawal_min' => (int) env('AUCTION_WITHDRAWAL_MIN', 50_000),

        // Deposits one buyer may start per hour.
        'deposits_per_hour' => 10,
    ],

    'billing' => [
        // Days a company whose plan payment failed can keep selling before
        // the subscription expires.
        'grace_days' => 7,

        // Days an invoice stays payable, and how long an emailed team
        // invitation stays valid.
        'invoice_due_days' => 7,
        'invitation_days' => 7,
    ],

    'listing' => [
        // How long an auction may run, and how far ahead it may be scheduled.
        'min_duration_minutes' => 60,
        'max_duration_days' => 30,
        'max_lead_days' => 90,

        // Pictures per product; each is stored full size and as a thumbnail.
        'max_images' => 10,
        'image_max_side' => 1600,
        'thumbnail_max_side' => 640,
    ],

    'bidding' => [
        // Share of a buyer's available balance that may be committed to bids.
        'biddable_share_bps' => 8000,

        // Bids (and buy-now attempts) one buyer may make per minute.
        'bids_per_minute' => 20,

        // A bid placed within this many seconds of the end extends the auction.
        'anti_snipe_window_seconds' => 120,
        'anti_snipe_extend_seconds' => 120,
        'anti_snipe_max_extensions' => 20,
    ],

    // Bid retraction, sign-in history, flags, strikes.
    'trust' => [
        // A buyer may take back their bids on an auction only within this many
        // minutes of their latest bid, and not in the auction's last minutes.
        'retract_window_minutes' => 60,
        'retract_closing_minutes' => 60,

        // Strikes count for this many days; this many active ones suspend the account.
        'strike_days' => 180,
        'strike_limit' => 3,

        // How far back a bidder's IP is compared with the seller team's sign-ins.
        'shared_ip_days' => 30,

        // Sign-in history is kept this long.
        'login_history_days' => 180,
    ],

    // VAT on the platform's own fees (commission, service and gateway
    // charges, plan payments). Prices include it; the ledger moves the VAT
    // part to the tax-payable account. Sale prices are not taxed.
    'tax' => [
        'vat_bps' => (int) env('AUCTION_VAT_BPS', 1500),
        'vat_number' => env('AUCTION_VAT_NUMBER'),
    ],

    // Who issues the invoices and receipts.
    'issuer' => [
        'name' => env('AUCTION_ISSUER_NAME', env('APP_NAME', 'Auction')),
        'address' => env('AUCTION_ISSUER_ADDRESS'),
        'email' => env('AUCTION_ISSUER_EMAIL'),
    ],

    'orders' => [
        // The company must ship within this many days of having the delivery
        // address, or the order is cancelled and the buyer refunded. A buyer
        // who gives no address within the same number of days of the sale
        // has the order cancelled too.
        'ship_by_days' => 5,

        // Escrow is released automatically this many days after shipment.
        // The buyer can open a dispute until then.
        'auto_release_days' => 7,

        // A buyer can review the seller this many days after the order completed.
        'review_days' => 60,
    ],

    'messaging' => [
        // Order chat and ticket replies a person can send per minute.
        'messages_per_minute' => 20,

        // Longest chat message, and longest ticket text, in characters.
        'message_max' => 2000,
        'ticket_max' => 5000,

        // New tickets one person (or email address) can open per hour.
        'tickets_per_hour' => 5,
    ],

    'kyc' => [
        // New buyers and companies must have their identity documents approved
        // before they bid, list or withdraw. Accounts never asked count as cleared.
        'required' => (bool) env('AUCTION_KYC_REQUIRED', true),

        // Each uploaded document: largest size in kilobytes and the accepted types.
        'max_kb' => 5120,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

        // How long a staff member's link to a document works.
        'link_minutes' => 10,
    ],

    'realtime' => [
        // Broadcasts go through their own queue, so a busy default queue never delays them.
        'queue' => env('BROADCAST_QUEUE', 'broadcasts'),

        // How long the guests' viewer count (read from the websocket server) is cached.
        'viewer_count_seconds' => 5,

        // Without a websocket connection, an open auction page refreshes this often.
        'fallback_poll_seconds' => 15,

        // Bids sent with each update of an auction page.
        'feed_size' => 15,
    ],

];

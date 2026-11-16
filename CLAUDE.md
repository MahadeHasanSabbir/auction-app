# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Online auction marketplace, mid-rebuild from a practice project ("v1") into a professional product ("v2") on the `v2` branch.

- **Stack:** Laravel 13 on PHP 8.4, Livewire 4 (which ships Alpine — never import or start Alpine in `resources/js/app.js`), Filament 5 for the admin and seller panels, Tailwind CSS 4 via `@tailwindcss/vite` (configured in `resources/css/app.css`; there is no `tailwind.config.js`), Vite 8, Laravel Reverb + Echo for websockets, MySQL.
- **Target model:** one shared marketplace. Seller companies subscribe to a plan (team size capped by plan) and work in a `/seller` panel; buyers register freely and bid from the public site; platform staff and the system owner work in `/admin`. See `app/Enums/UserRole.php`.
- **State of the rebuild:** the foundation (Phase 0), the data layer (Phase 1) and accounts plus the public site (Phase 2: sign-up, sign-in with emailed codes, profile and security pages, the design system, home, about, auction list and auction detail) are done. No business action exists yet: nothing can place a bid, move money, invite a member or approve an auction, and both Filament panels are empty shells.

## Conventions for new code

- **Money** is an integer number of minor units (paisa) in `BIGINT` columns, handled through `App\Support\Money`; rates are basis points. No floats, no `decimal` columns. Models cast money columns with `App\Casts\MoneyCast`, so `$auction->current_price` is a `Money`; the cast accepts a `Money` or an `int` and throws on anything else.
- **Business operations** live in single-purpose classes under `app/Actions/` and run inside DB transactions; controllers, Livewire components, Filament pages and the API all call the same action.
- **Statuses and roles** are the backed enums in `app/Enums/`, never bare integers or strings. `AuctionStatus` and `OrderStatus` define the allowed transitions.
- **Authorization** goes through policies. Every state change is a non-GET request.
- **Interface text** goes through `__()`; times are stored in UTC.
- **Models** use explicit `$fillable`, and it lists only what a form may set. Columns that decide privilege or money (`users.role`, `users.company_id`, `auctions.status`, wallet balances, order amounts, …) are left out on purpose and are set with property assignment or `forceFill()` by the code that is allowed to. Factories are unguarded, so tests can set anything.
- **Lazy loading throws** outside production (`AppServiceProvider`), in tests and seeders too, so eager-load relations.
- **Polymorphic columns** use the morph map in `AppServiceProvider` (`enforceMorphMap`); add a model there before using it as a ledger reference, notifiable or activity-log subject.
- Queue jobs and broadcasts are dispatched after commit (`after_commit` is on for every queue connection).
- **Migrations are edited in place** until the first release (data starts fresh, there is nothing to preserve). Group new tables into the existing per-domain migration file rather than adding `alter` migrations.

## Commands

PHP must be 8.4 (with the `gd` and `exif` extensions, among the usual Laravel ones); the build fails on older versions. First-time setup (no `.env` or `vendor/` is committed):

```
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed        # system owner, plans, fee rules, categories + demo data
npm run build
php artisan storage:link   # uploaded pictures are served from the public disk
```

Day to day:

```
composer dev               # serve + queue + reverb + scheduler + vite together
php artisan serve          # or run the pieces separately
npm run dev
php artisan reverb:start   # websocket server (BROADCAST_CONNECTION=reverb)
npm run build              # production assets into public/build
php artisan migrate:fresh --seed   # rebuild the dev database with demo data

composer check             # pint --test, phpstan, tests: run before finishing any change
composer test              # all tests (PHPUnit 13)
php artisan test --filter=MoneyTest                    # one test class
php artisan test --filter=test_it_adds_and_subtracts   # one test method
php artisan test tests/Unit/Support/MoneyTest.php      # one file
composer lint              # fix code style (Laravel Pint, pint.json)
composer analyse           # Larastan level 6 (phpstan.neon)
php artisan log:clear      # custom command in routes/console.php
```

Notes:

- **Seeding:** `PlatformSeeder` creates what every install needs (system owner, platform ledger accounts, user types, plans, fee rules, categories) and is safe to re-run. `DemoSeeder` adds staff, five companies, twelve buyers and auctions in every status; it is skipped in production and when companies already exist. Logins: `admin@auction.com` / `admin` (system owner; override with `AUCTION_OWNER_*`), and with password `password`: `staff@auction.com`, `owner@dhaka-antiques.test` (seller), `buyer1@auction.com` … `buyer12@auction.com`.
- **Static analysis has no baseline.** Larastan must pass clean; fix the code instead of ignoring an error. Run it from a shell whose working directory uses an upper-case drive letter (`D:\…`): with a lower-case one (Git Bash `/d/…`) Larastan wrongly reports every `env()` call in `config/`.
- **Tests run on MySQL, in a separate database `auction_test`** (`phpunit.xml`), using the host and credentials from `.env`. Create it once: `CREATE DATABASE auction_test`. The dev database `auction` is never touched by tests. CI (`.github/workflows/ci.yml`) overrides the connection through real environment variables.
- **The app database is MySQL 8.4** (`.env.example`, `config/database.php`). Sessions, cache and queue use the `database` driver, so their tables must be migrated. The schema relies on MySQL generated columns (see below), so SQLite is not an option for tests.
- **Broadcasting** is selected by `BROADCAST_CONNECTION` (`reverb` locally, `pusher` for hosting without long-running processes); `resources/js/echo.js` follows the same switch through `VITE_BROADCAST_CONNECTION`.
- **Filament assets** in `public/css|js|fonts/filament` are gitignored and republished by `php artisan filament:upgrade`, which Composer runs after every install or update.
- Treat the README as out of date; the code is the source of truth.

## Architecture

### Accounts and roles

`users.role` is cast to `UserRole`: `system_owner` and `platform_staff` (platform side), `company_owner` and `company_member` (seller side, `company_id` set), `buyer`. A `saving` hook on `User` throws unless company roles have a company and every other role has none. A new `User` is a buyer by default, and `role`/`company_id` cannot be mass-assigned, so a sign-up form cannot choose its role. Users and companies are soft-deleted because bids, orders and ledger rows reference them.

`User::canAccessPanel()` decides panel access: platform roles get `/admin`, company roles get `/seller`, inactive accounts get neither. The seller panel uses Filament tenancy with `Company` as the tenant (`/seller/{company-slug}`); `canAccessTenant()` allows only the user's own company, and resources added to that panel are scoped to it automatically. Both panels are empty shells (`app/Providers/Filament/`); resources go in `app/Filament/Admin/` and `app/Filament/Seller/`.

### Sign-up, sign-in and codes

There is **one sign-in page for every role** (`/login`); the Filament panels have no login page and send guests there. `App\Support\Home::pathFor()` says where each role works (`/admin`, `/seller`, `/dashboard`) and is used for every post-login redirect. Inactive and deleted accounts cannot sign in.

- **Sign-up** (`RegisteredUserController`) takes `account_type` buyer or seller and calls `App\Actions\Accounts\RegisterBuyer` (buyer + wallet) or `RegisterCompany` (pending company + owner + company wallet). Choosing a plan is deferred to the seller panel (Phase 4).
- **Emailed codes** come from `App\Services\Otp\OtpService` (`config/auction.php` → `otp`): six digits, stored hashed in `otp_codes`, ten minutes, single use, five wrong guesses, a resend cooldown and an hourly cap. One live code per user and `OtpPurpose`. The mail is sent synchronously, not queued. With the default `MAIL_MAILER=log` it lands in `storage/logs/laravel.log`; in the `local` environment only, the plain code is also flashed to the session and shown on the code page.
- **Email verification** uses a code, not a signed link (`User::sendEmailVerificationNotification()` is overridden; `EmailVerificationController`). Both panels require a verified email too.
- **Sign-in is two steps:** `AuthenticatedSessionController` checks the password and emails a code without signing anyone in (the pending user id sits in the session under `login.challenge`); `LoginCodeController` checks the code and signs in. A browser the user chose to trust (`App\Services\TrustedDevices`, encrypted cookie + hash in `trusted_devices`, 30 days) skips the code. Changing the password forgets every trusted device.
- **Step-up:** the `otp.confirmed` middleware (`EnsureOtpConfirmed`) guards `/profile/security` and everything on it (email, password, trusted devices, closing the account). It wants a code entered within the last 15 minutes; a sign-in with a code or an email verification counts, a trusted-device sign-in does not. Put the same middleware on later sensitive actions (withdrawals, payout details).
- Only buyers can close their own account, and only with an empty wallet; the user is soft-deleted.
- Code that compares against "now" uses `now()`, not `time()`, so tests can travel in time.

### Public site and design system

- **Layouts:** `layouts/site.blade.php` (header, footer, toasts) and `layouts/auth.blade.php` (centred card). Blade pages use `<x-site-layout title="…">` / `<x-auth-layout>`; full-page Livewire components use `#[Layout('layouts.site')]` and set the title with `->title(__('…'))` in `render()`. Signed-in account pages wrap their content in `<x-account.shell>`.
- **Livewire pages** (`app/Livewire/`): `Home`, `Auctions\Index` (search, status, category, seller, price and sort, all bound to the query string), `Auctions\Show` (read-only until the bidding phase), `Account\Overview` (buyers only). Cards need the eager loads of `Auction::forCards()`.
- **Components** (`resources/views/components/`): `button`, `field`, `card`, `badge`, `money`, `countdown`, `datetime`, `flash` (toasts from session `status` / `error`, or `$this->dispatch('toast', message: …)`), `empty-state`, `avatar`, `product-image`, `otp-form`, `auction.card`, `auction.status`, `site.header` (class component; loads wallet balance and notifications), `site.footer`. Icons are `<x-app-icon name="…">`. **`<x-icon>` belongs to the blade-icons package that Filament installs**, so never create a component with that name.
- **Theme** lives in `resources/css/app.css`: brand colour scale `brand-50…950` (an indigo-leaning blue), neutrals `slate`, Inter for text and `font-display` (Space Grotesk) for headings, flat bordered cards with `rounded-md`/`rounded-lg` corners, `.form-control` and `.tabular`. Dark mode is the `dark` class on `<html>`, set before first paint by an inline script and toggled through the Alpine store `$store.theme`. Alpine pieces (`countdown`, `cooldown`, the theme store) are registered in `resources/js/app.js` on `alpine:init`.
- **Times** are shown with `<x-datetime>`, which converts UTC to the viewer's `users.timezone` or `auction.display_timezone`. Countdowns run on the server's clock, not the visitor's.
- **Bidder privacy:** other bidders are shown as `User::maskedName()`; the reserve amount is never rendered, only whether it is met.
- **Uploads** go through `App\Services\ImageStore`: the picture is decoded, turned upright (needs the `exif` PHP extension), resized and re-encoded as WebP under a random name on the `public` disk (`php artisan storage:link`). Only avatars use it so far.

### Schema, by migration file

Each file under `database/migrations/` holds one domain:

- `0001_01_01_000000_create_users_table` — `user_types`, `users`, sessions, `otp_codes`, `trusted_devices`.
- `…_000100_create_company_tables` — `plans`, `companies`, `company_invitations`, `subscriptions` (one row per company, updated in place), `company_agreements` (per-company commission set by the system owner).
- `…_000200_create_catalogue_tables` — `categories` (nested), `products`, `product_images`.
- `…_000300_create_auction_tables` — `auctions`, `bids` (append-only, `seq` unique per auction), `auto_bids`, `watchlists`, `idempotency_keys`.
- `…_000400_create_wallet_tables` — `fee_rules`, `wallets`, `ledger_transactions`, `ledger_entries`, `deposits`, `withdrawal_requests`, `wallet_holds`, `subscription_invoices`.
- `…_000500_create_order_tables` — `orders`, `shipments`, `order_events`, `reviews`, `disputes`.
- `…_000600_create_communication_tables` — `conversations`, `conversation_participants`, `messages`, `notifications`, `notices`.
- `…_000700_create_compliance_tables` — `kyc_requests`, `kyc_documents`, `settings`; `…_000800` is spatie's `activity_log`.

Tables for the later phases (social logins, fraud flags, invoices, tax, pages, coupons, API tokens, …) are added when those phases are built.

Things that are not obvious from any single file:

- **The wallet is a double-entry ledger.** A wallet belongs to a buyer, a company or a platform account (`SystemAccount`: escrow, gateway clearing, commission revenue, …). `ledger_entries.amount` is signed (positive credits the wallet) and the entries of one `ledger_transactions` row sum to zero. `wallets.balance` and `wallets.held` are caches of the entries and the active `wallet_holds`; nothing may write them except the ledger code, in the same transaction as the rows they summarise. `Wallet::available()` is balance minus held. Platform accounts run negative by design (gateway clearing mirrors money held at the gateway).
- **Two rules are enforced by generated columns with unique indexes:** an auction has at most one active bid hold (`wallet_holds.active_bid_auction_id`) and an order at most one open dispute (`disputes.open_order_id`). The columns are null for every other row. Never write them.
- **Auction state is cached on the row.** `current_price`, `bid_count`, `leader_id` and `reserve_met` are written only by the bidding actions under a row lock; `current_price` equals `starting_price` until the first bid (set by a `creating` hook). Anti-sniping settings and the commission (`fee_snapshot`) are copied onto the auction when it is approved, so later changes do not affect a running auction.
- **A seller proposes a schedule, the platform approves the real one:** `proposed_starts_at/ends_at` versus `starts_at/ends_at`. `original_ends_at` and `extension_count` record anti-sniping extensions.
- **Fees:** `fee_rules` are platform-wide charges per use case, optionally per user type and gateway; `company_agreements` override the sale commission for one company. Both have an `effectiveAt()` scope.
- **There is no Ledger service yet.** `database/seeders/Support/DemoLedger.php` posts the demo money movements as real balanced entries, without locking or fee resolution; it is seed-only and is replaced by the real service in Phase 3.

### What is left of v1

Only the Breeze password-reset controllers (`PasswordResetLinkController`, `NewPasswordController`, which still email a link) and the `dropdown`, `dropdown-link`, `input-error` and `modal` components, restyled. Everything else has been replaced.

## Tests

`tests/Feature/Models/` covers the role rules, the database-enforced constraints and every factory; `tests/Feature/Seeders/DemoSeederTest.php` checks that the demo data covers every auction status and that its ledger balances; `tests/Feature/Panels/PanelAccessTest.php` covers who may open which panel. `tests/Feature/Auth/` covers the code service, sign-up, two-step sign-in, trusted devices and email verification; `tests/Feature/Account/` the step-up gate, the security settings and the buyer overview; `tests/Feature/Auctions/` the list filters and the detail page; `PublicPagesTest` renders every public page against the demo data. Tests that go through an emailed code call `Notification::fake()` and use the `Tests\Support\InteractsWithOtp` trait (`emailedCode()`, `withConfirmedOtp()`). When adding a model, add its factory to the data provider in `FactoryTest`.

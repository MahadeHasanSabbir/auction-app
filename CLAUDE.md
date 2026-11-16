# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Online auction marketplace, mid-rebuild from a practice project ("v1") into a professional product ("v2") on the `v2` branch.

- **Stack:** Laravel 13 on PHP 8.4, Livewire 4 (which ships Alpine — never import or start Alpine in `resources/js/app.js`), Filament 5 for the admin and seller panels, Tailwind CSS 4 via `@tailwindcss/vite` (configured in `resources/css/app.css`; there is no `tailwind.config.js`), Vite 8, Laravel Reverb + Echo for websockets, MySQL.
- **Target model:** one shared marketplace. Seller companies subscribe to a plan (team size capped by plan) and work in a `/seller` panel; buyers register freely and bid from the public site; platform staff and the system owner work in `/admin`. See `app/Enums/UserRole.php`.
- **State of the rebuild:** the foundation (Phase 0) and the data layer (Phase 1: schema, models, factories, seed data, empty Filament panels) are done. The v1 controllers, views and migrations are gone. No business action exists yet: nothing can place a bid, move money, invite a member or approve an auction. The public pages, Breeze auth screens and layouts are still the v1 look and are rebuilt in Phase 2.

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

PHP must be 8.4; the build fails on older versions. First-time setup (no `.env` or `vendor/` is committed):

```
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed        # system owner, plans, fee rules, categories + demo data
npm run build
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

Login is still Breeze (`routes/auth.php`); it refuses inactive accounts and redirects by role. `User` implements `MustVerifyEmail`; with the default `MAIL_MAILER=log` the verification link is written to `storage/logs/laravel.log`.

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

The Breeze auth controllers and views, `ProfileController` (name, email, password, delete), the Blade components in `resources/views/components/`, the two layouts and navigation, and the `welcome` and `aboutus` pages (standalone HTML documents that `@include('layouts.navigation')`). `/auctions` is a plain controller-rendered list (`AuctionListController`) standing in until the Livewire rebuild. Product images will use the `public` disk (`php artisan storage:link`); there is no upload code at the moment.

## Tests

`tests/Feature/Models/` covers the role rules, the database-enforced constraints and every factory; `tests/Feature/Seeders/DemoSeederTest.php` checks that the demo data covers every auction status and that its ledger balances; `tests/Feature/Panels/PanelAccessTest.php` covers who may open which panel. The Breeze auth and profile tests remain. When adding a model, add its factory to the data provider in `FactoryTest`.

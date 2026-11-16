# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Online auction marketplace, mid-rebuild from a practice project ("v1") into a professional product ("v2") on the `v2` branch.

- **Stack:** Laravel 13 on PHP 8.4, Livewire 4 (which ships Alpine — never import or start Alpine in `resources/js/app.js`), Filament 5 for the admin and seller panels, Tailwind CSS 4 via `@tailwindcss/vite` (configured in `resources/css/app.css`; there is no `tailwind.config.js`), Vite 8, Laravel Reverb + Echo for websockets, MySQL.
- **Target model:** one shared marketplace. Seller companies subscribe to a plan (team size capped by plan) and work in a `/seller` panel; buyers register freely and bid from the public site; platform staff and the system owner work in `/admin`. See `app/Enums/UserRole.php`.
- **State of the rebuild:** the foundation is in place (framework upgrade, tooling, enums in `app/Enums/`, `App\Support\Money`, `config/auction.php`). The controllers, models, migrations and views are still the v1 code described under "Architecture (v1 code still in place)" and are replaced phase by phase. New code must not follow the v1 patterns.

## Conventions for new code

- **Money** is an integer number of minor units (paisa) in `BIGINT` columns, handled through `App\Support\Money`; rates are basis points. No floats, no `decimal` columns.
- **Business operations** live in single-purpose classes under `app/Actions/` and run inside DB transactions; controllers, Livewire components, Filament pages and the API all call the same action.
- **Statuses and roles** are the backed enums in `app/Enums/`, never bare integers or strings. `AuctionStatus` and `OrderStatus` define the allowed transitions.
- **Authorization** goes through policies. Every state change is a non-GET request.
- **Interface text** goes through `__()`; times are stored in UTC.
- **Models** use explicit `$fillable`. Lazy loading throws outside production (`AppServiceProvider`), so eager-load relations.
- Queue jobs and broadcasts are dispatched after commit (`after_commit` is on for every queue connection).

## Commands

PHP must be 8.4; the build fails on older versions. First-time setup (no `.env` or `vendor/` is committed):

```
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed        # creates the super admin: admin@auction.com / admin
npm run build
```

Day to day:

```
composer dev               # serve + queue + reverb + scheduler + vite together
php artisan serve          # or run the pieces separately
npm run dev
php artisan reverb:start   # websocket server (BROADCAST_CONNECTION=reverb)
npm run build              # production assets into public/build

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

- **Static analysis baseline:** `phpstan-baseline.neon` holds errors from v1 code only. Never add new entries; fix the code. Remove entries as v1 files are deleted (`reportUnmatchedIgnoredErrors` makes stale entries fail).
- **Tests run on MySQL, in a separate database `auction_test`** (`phpunit.xml`), using the host and credentials from `.env`. Create it once: `CREATE DATABASE auction_test`. The dev database `auction` is never touched by tests. CI (`.github/workflows/ci.yml`) overrides the connection through real environment variables.
- **The app database is MySQL 8.4** (`.env.example`, `config/database.php`). Sessions, cache and queue use the `database` driver, so their tables must be migrated.
- **Broadcasting** is selected by `BROADCAST_CONNECTION` (`reverb` locally, `pusher` for hosting without long-running processes); `resources/js/echo.js` follows the same switch through `VITE_BROADCAST_CONNECTION`.
- Treat the README as out of date; the code is the source of truth.

## Architecture (v1 code still in place)

### Roles

`users.role` is an integer, with no enum or constants; the literal values are compared throughout controllers and views:

- `0` – regular user (sells products, bids, pays)
- `1` – admin (created by another admin via `/add-admin`)
- `2` – super admin (only from `DatabaseSeeder`; receives the commission on every payment)

The `admin` middleware alias (`app/Http/Middleware/Admin.php`, registered in `bootstrap/app.php`) lets through any `role != 0`. Super-admin-only UI is gated in Blade with `role == 2` (see `layouts/navigation.blade.php`), not by middleware. Only role-0 users who are not the host can bid.

The `UserRole` enum describes the v2 roles and is not wired to this column yet.

`User` implements `MustVerifyEmail`, and nearly all routes carry `verified`. With the default `MAIL_MAILER=log`, the verification link is written to `storage/logs/laravel.log`.

### Auction lifecycle

Products, auctions and payments are all created in a single migration, `2024_04_04_141032_create_bidding_table.php`.

`auctions.status` is the state machine (again bare integers):

| status | meaning | set by |
|---|---|---|
| `0` | requested, awaiting admin review | `AuctionController@store` |
| `1` | approved | `AdminController@accept` — sets `start_time = now + 30 min`, `end_time = now + 4 h` |
| `2` | paid/completed | `PaymentController@pay` |
| `3` | denied (reason stored in `massage`) | `AdminController@deny` |

Things that are not obvious from any single file:

- **There is no bids table and no scheduler.** A bid is `AuctionController@update`, which overwrites `auctions.final_price` and `auctions.owner_id` (current highest bidder) and increments `no_of_bid`. `final_price` starts as the host's asking price at creation.
- **"Upcoming", "ongoing" and "ended" are never stored.** They are derived at render time by comparing `start_time`/`end_time` with `date('Y-m-d H:i:s')` while status is `1`. The winner is whoever is `owner_id` once `end_time` has passed.
- **Money is an internal wallet**, `users.asset` (integer, default 50). `ProfileController@fund` tops it up with no real gateway. `PaymentController@pay` debits the winner, credits the host with the amount minus commission, and credits the commission — 35% of `final_price - starting_price` — to role-2 users. `withdraw` then debits the host's wallet and records `payments.withdrawer`.
- The column `auctions.massage` (sic) is overloaded: it holds the admin's denial reason for status 3 and the buyer's review for status 2. The spelling is used consistently in code and views, so keep it unless you migrate it everywhere.
- Ownership columns `products.seller`, `auctions.host_id`, `auctions.owner_id`, `payments.payer` and `payments.withdrawer` are plain unsigned integers with no foreign keys or Eloquent relations to `User`. Only `Product hasOne Auction` and `Auction hasOne Payment` exist as relations.

### Views do their own queries

Many Blade views query the database directly inside `@php` blocks with the `DB` facade rather than receiving data from a controller: `auction.blade.php`, `dashboard.blade.php`, `welcome.blade.php`, `admin.blade.php`, `ongoing.blade.php`, `payment.blade.php`, `users.blade.php`. Several routes (`/`, `/auctions`, `/dashboard`) are closures that only return a view. When changing how auctions are listed or filtered, look in the view as well as the controller; the "ongoing" query is duplicated across `AdminController@index` and several views.

The public pages (`welcome`, `auction`, `ongoing`, `productview`) are standalone HTML documents that `@include('layouts.navigation')`; authenticated pages use the Breeze `<x-app-layout>` component. Views under `resources/views/profile/` hold most of the logged-in screens (product management, auction requests, payment history, contact inbox), not only profile editing.

### Routing

`routes/web.php` registers the same resource controller several times with different middleware and `only`/`except` lists, so that `show` is public while the rest needs auth (`product`, `auction`, `contact`). Auction creation is the nested route `product.auction.create` (`/product/{product}/auction/create`). `ContactController@show` is used as "mark as read" and redirects instead of rendering.

### File uploads

Product pictures and avatars are saved with `$request->file->move('storage', $name)`, which writes into `public/storage/` relative to the web root — not through Laravel's `Storage` disks. The stored DB value is the relative path (`storage/123.jpg`) and is rendered with `asset()`. `public/storage` is gitignored; do not run `php artisan storage:link`, since a symlink there would redirect these uploads.

## Tests

Feature tests are still the stock Breeze ones (`tests/Feature/Auth/*`, `ProfileTest`); there is no coverage of the v1 product, auction, payment or admin flows. New v2 code is covered as it is written (`tests/Unit/Support/MoneyTest.php`).

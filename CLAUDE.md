# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Online auction marketplace, mid-rebuild from a practice project ("v1") into a professional product ("v2") on the `v2` branch.

- **Stack:** Laravel 13 on PHP 8.4, Livewire 4 (which ships Alpine — never import or start Alpine in `resources/js/app.js`), Filament 5 for the admin and seller panels, Tailwind CSS 4 via `@tailwindcss/vite` (configured in `resources/css/app.css`; there is no `tailwind.config.js`), Vite 8, Laravel Reverb + Echo for websockets, MySQL.
- **Target model:** one shared marketplace. Seller companies subscribe to a plan (team size capped by plan) and work in a `/seller` panel; buyers register freely and bid from the public site; platform staff and the system owner work in `/admin`. See `app/Enums/UserRole.php`.
- **State of the rebuild:** the foundation (Phase 0), the data layer (Phase 1) and accounts plus the public site (Phase 2: sign-up, sign-in with emailed codes, profile and security pages, the design system, home, about, auction list and auction detail) are done, and so is the money core (Phase 3a: `Ledger`, holds, `ledger:verify`, `FeeResolver`, the payment-gateway contract with a fake driver, the deposit actions; Phase 3b: SSLCommerz and Stripe drivers, payment callbacks, the buyer wallet and withdraw pages, withdrawal actions). Phase 4 (seller companies, plans and billing) is done too: the seller panel has plan & billing, team, company profile and wallet pages, and the admin panel has plans, companies and commission agreements. Phase 5a (products and auction listing) is done: sellers keep products with pictures and draft auctions, send them for review, and platform staff approve or reject them in the admin review queue. Phase 5b (the auction engine) is done: `auctions:sweep` starts and closes auctions, buyers bid by hand or automatically, buy now, watch auctions and see them on "My bids", and a sale opens an order with the price in escrow. Staff have no screen for withdrawals yet (Phase 9a); orders have no screens yet (Phase 7) and nothing updates live (Phase 6; the auction page polls every 10 seconds).

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

- **Sign-up** (`RegisteredUserController`) takes `account_type` buyer or seller and calls `App\Actions\Accounts\RegisterBuyer` (buyer + wallet) or `RegisterCompany` (pending company + owner + company wallet). The plan is chosen in the seller panel (see Companies, plans and billing).
- **Emailed codes** come from `App\Services\Otp\OtpService` (`config/auction.php` → `otp`): six digits, stored hashed in `otp_codes`, ten minutes, single use, five wrong guesses, a resend cooldown and an hourly cap. One live code per user and `OtpPurpose`. The mail is sent synchronously, not queued. With the default `MAIL_MAILER=log` it lands in `storage/logs/laravel.log`; in the `local` environment only, the plain code is also flashed to the session and shown on the code page.
- **Email verification** uses a code, not a signed link (`User::sendEmailVerificationNotification()` is overridden; `EmailVerificationController`). Both panels require a verified email too.
- **Sign-in is two steps:** `AuthenticatedSessionController` checks the password and emails a code without signing anyone in (the pending user id sits in the session under `login.challenge`); `LoginCodeController` checks the code and signs in. A browser the user chose to trust (`App\Services\TrustedDevices`, encrypted cookie + hash in `trusted_devices`, 30 days) skips the code. Changing the password forgets every trusted device.
- **Step-up:** the `otp.confirmed` middleware (`EnsureOtpConfirmed`) guards `/profile/security` and everything on it (email, password, trusted devices, closing the account). It wants a code entered within the last 15 minutes; a sign-in with a code or an email verification counts, a trusted-device sign-in does not. `/wallet/withdraw` uses it too. Livewire actions do not pass through route middleware, so a sensitive Livewire action re-checks with `EnsureOtpConfirmed::confirmedIn(session()->driver())`.
- Only buyers can close their own account, and only with an empty wallet; the user is soft-deleted.
- Code that compares against "now" uses `now()`, not `time()`, so tests can travel in time.

### Public site and design system

- **Layouts:** `layouts/site.blade.php` (header, footer, toasts) and `layouts/auth.blade.php` (centred card). Blade pages use `<x-site-layout title="…">` / `<x-auth-layout>`; full-page Livewire components use `#[Layout('layouts.site')]` and set the title with `->title(__('…'))` in `render()`. Signed-in account pages wrap their content in `<x-account.shell>`.
- **Livewire pages** (`app/Livewire/`): `Home`, `Auctions\Index` (search, status, category, seller, price and sort, all bound to the query string), `Auctions\Show` (bidding, buy now, watchlist), `Account\Overview` and `Account\Bids` (buyers only). Cards need the eager loads of `Auction::forCards()`.
- **Components** (`resources/views/components/`): `button`, `field`, `card`, `badge`, `money`, `countdown`, `datetime`, `flash` (toasts from session `status` / `error`, or `$this->dispatch('toast', message: …)`), `empty-state`, `avatar`, `product-image`, `otp-form`, `auction.card`, `auction.status`, `site.header` (class component; loads wallet balance and notifications), `site.footer`. Icons are `<x-app-icon name="…">`. **`<x-icon>` belongs to the blade-icons package that Filament installs**, so never create a component with that name.
- **Theme** lives in `resources/css/app.css`: brand colour scale `brand-50…950` (an indigo-leaning blue), neutrals `slate`, Inter for text and `font-display` (Space Grotesk) for headings, flat bordered cards with `rounded-md`/`rounded-lg` corners, `.form-control` and `.tabular`. Dark mode is the `dark` class on `<html>`, set before first paint by an inline script and toggled through the Alpine store `$store.theme`. Alpine pieces (`countdown`, `cooldown`, the theme store) are registered in `resources/js/app.js` on `alpine:init`.
- **Times** are shown with `<x-datetime>`, which converts UTC to the viewer's `users.timezone` or `auction.display_timezone`. Countdowns run on the server's clock, not the visitor's.
- **Bidder privacy:** other bidders are shown as `User::maskedName()`; the reserve amount is never rendered, only whether it is met.
- **Uploads** go through `App\Services\ImageStore`: the picture is decoded, turned upright (needs the `exif` PHP extension), resized and re-encoded as WebP under a random name on the `public` disk (`php artisan storage:link`). Avatars, company logos and product pictures use it; product pictures are stored twice, full size and as a thumbnail (`auction.listing.*`).

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
### Money: ledger, fees, gateways

- **`App\Services\Ledger\Ledger`** (scoped singleton) is the only code that moves money or sets it aside. Post with the builder: `$ledger->transaction(LedgerTransactionType::X)->reference($model)->idempotent($key)->debit($wallet, $money)->credit($ledger->system(SystemAccount::Escrow), $money, LedgerComponent::…, $feeRuleOrAgreementOrTerms)->capturing($hold)->post()`. It checks the entries sum to zero in one currency, refuses a member wallet spending more than balance minus held (`InsufficientFundsException`; platform accounts may go negative), writes `balance_after`, updates the caches and syncs the caller's models. An idempotency key posts once and returns the first transaction. `hold()` / `release()` manage `wallet_holds`; `capturing($hold)` consumes a hold inside a post.
- **Lock order** for every money path: the domain row first (auction, order, deposit, withdrawal) with `lockForUpdate()`, then `Ledger::lock()` once with every member wallet involved (members by ascending id, then platform accounts by ascending id), then holds. Do not lock a wallet any other way.
- **`ledger:verify`** (`LedgerVerifier`, scheduled daily, logs critical on failure) checks balanced transactions, cached balance/held, running balances, overdrawn member wallets, currency, escrow = open orders (`OrderStatus::holdsEscrow()`, `amount - refunded_amount`) and that every active bid hold belongs to the leader of a live auction.
- **`App\Services\Fees\FeeResolver`**: `rule()` / `charge()` / `charges()` pick the most specific active rule (user type, then gateway, then newest); `saleCommission($company)` returns `CommissionTerms` from the company's agreement, else the default rule. `CommissionTerms::toSnapshot()` is what goes into `auctions.fee_snapshot['sale_commission']`, and `fromSnapshot()->apply($final, $starting)` computes the commission later.
- **Payments:** `App\Services\Payments\PaymentGateway` (`checkout()` → redirect URL, `verify(Request)` → confirmed `PaymentResult`), resolved by name through `PaymentGateways` from `config('auction.payments.gateways')` (`PAYMENT_GATEWAYS`). `FakeGateway` pays at once with an HMAC-signed redirect and is refused in production; tests build callbacks with `FakeGateway::callback()`. `SslCommerzGateway` and `StripeGateway` use the HTTP client (no SDKs; credentials in `config/services.php`): SSLCommerz successes are confirmed through its validation API by `val_id`, Stripe through a session lookup on return or an HMAC-checked webhook. `verify()` throws `InvalidCallbackException` (refuse) or `IgnoredCallbackException` (genuine, nothing to do yet).
- **Callbacks** (`routes/payments.php`, `PaymentCallbackController`) are loaded in `bootstrap/app.php` **without the web middleware** (no session, cookies or CSRF): SSLCommerz posts back cross-site, and a fresh session would sign the payer out. `/payments/{gateway}/return` completes the deposit and redirects to `/wallet?deposit={tran_id}`, where the page reads the deposit's own status; `/payments/{gateway}/notify` is the IPN/webhook (200, or 400 when refused).
- **Deposits:** `App\Actions\Wallet\StartDeposit` (buyers only; limits in `auction.payments.deposit_*`; quotes gateway + service charges, stores the rule ids, creates a pending `Deposit`, calls the gateway outside any transaction) and `CompleteDeposit` (locks the deposit, credits net to the buyer, charges to `GatewayCharges`/`ServiceRevenue`, debits `GatewayClearing` the full amount; replays credit once, a late success after a failure is still credited, a different amount is refused). `Deposit` has an empty `$fillable`; the actions use `forceFill()`.
- **Withdrawals** (`RequestWithdrawal`, `CancelWithdrawal`, `ApproveWithdrawal`, `RejectWithdrawal`, `PayWithdrawal` in `app/Actions/Wallet/`): a buyer, or a company owner for the company wallet (`WalletPolicy::withdraw`), asks for an amount to a `PayoutMethod` (bank, bKash, Nagad, Rocket; destination validated per method and stored encrypted). Charges are quoted from the `Withdrawal` fee rules and the amount is held at once. `pending → approved → paid`, or `rejected` (reason, hold released), or `cancelled` by the requester while pending. Paying captures the hold: wallet debited the amount, charges to `GatewayCharges`/`ServiceRevenue`, the net to `PayoutClearing`. Staff actions need `WithdrawalRequestPolicy::review` (platform, not their own) and are written to the activity log (`money` log, causer, reason or reference). `ledger:verify` also checks every open request has exactly its hold.
- **Wallet pages** (buyers): `/wallet` (`App\Livewire\Wallet\Show`: balances, add money with a live charge quote, recent payments, withdrawals with cancel, paginated statement) and `/wallet/withdraw` (`Withdraw`, behind `otp.confirmed`). Starting a deposit is limited to `auction.payments.deposits_per_hour`.
- `DemoSeeder` moves its demo money through the same `Ledger`, `CompleteDeposit` and withdrawal actions (one paid, one pending withdrawal).

### Companies, plans and billing

- **Panel theme:** both panels use `resources/css/filament/panels/theme.css` (`->viteTheme()`, built by `npm run build`), with Tailwind's blue as primary, slate grays and the site fonts. Filament's prebuilt CSS only holds Filament's own classes, so Tailwind classes in our panel views work only because this theme scans `app/Filament` and `resources/views/filament`. Prefer Filament's own pieces (tables, actions with confirmation modals, schemas, `x-filament::section` / `callout` / `badge`) over hand-made markup. Panel dates use `config('auction.display_timezone')` (`FilamentTimezone` in `AppServiceProvider`).

- **Seller panel** (`app/Filament/Seller/Pages/`, views in `resources/views/filament/seller/`): `Billing` (choose, change, cancel a plan; pay invoices), `Team` (invite, withdraw, remove, seat counter), `CompanyProfile`, `CompanyWallet` (balances, payout request, statement). Pages are Livewire methods plus Blade, calling the same actions as anything else. Members can look; the owner acts (`CompanyPolicy::manageBilling/manageTeam/manageProfile`). The `RequirePlan` tenant middleware sends a company with no plan (or an unpaid first invoice) to the billing page on page loads; a status banner (render hook) shows past-due, expired, trial-ending, pending and suspended states. The company payout request re-checks `EnsureOtpConfirmed::confirmedIn()` in the action.
- **Billing actions** (`app/Actions/Billing/`): `SubscribeToPlan` (trial once per company, free plan active at once, otherwise status `incomplete` + an open invoice), `ChangePlan` (trial: immediate; dearer per month: prorated invoice, plan changes when paid; cheaper: scheduled in `pending_plan_id`, applied at renewal; seat check), `CancelSubscription` (also resume), `IssueInvoice` (one open invoice per subscription; others are voided), `PayInvoice` (`fromWallet` / `fromGateway`; credits `SubscriptionRevenue`, debits the company wallet or gateway clearing; settling starts the period and applies the invoice's plan), `StartInvoicePayment` (fresh `tran_id` `INV…` per attempt), `RenewSubscription`. `subscriptions:renew` (hourly): cancelled → expires at period end; else bills the next period and pays from the company wallet if it can, otherwise `past_due` with `grace_days`; each run retries, then expires (open invoices voided; the company re-subscribes with `SubscribeToPlan`). `Subscription::allowsSelling()` is what Phase 5 must check before a listing. Payment callbacks tell invoices (`INV…`) from deposits (`DEP…`) by prefix and send the payer back to the billing page.
- **Team** (`app/Actions/Team/`): `InviteMember` counts members plus pending invitations against the plan's seats (and a scheduled downgrade's); the address must not have an account. The emailed link (`/invitations/{token}`, `InvitationController`) creates a verified `company_member` account and does not sign in. `RemoveMember` soft-deletes, signs out, frees the seat; an owner cannot be removed.
- **Admin** (`app/Filament/Admin/Resources/`, system owner only via `PlanPolicy`, `CompanyPolicy`, `CompanyAgreementPolicy`): `PlanResource` (money fields use `App\Filament\Support\MoneyInput`), `CompanyResource` (list + view; actions in `Companies/Actions/CompanyActions` call `VerifyCompany`, `SuspendCompany`, `ReinstateCompany`, `SetAutoApprove`, `AssignPlan`, which log to the `admin` activity log), and an agreements relation manager (rate typed as %, stored as bps).

### Products and auction listing

- **Seller panel resources** (`app/Filament/Seller/Resources/`): `Products` (pictures through a `FileUpload` with `storeFiles(false)`, handed to the action) and `Auctions` (draft form, view page with the lifecycle actions in `AuctionActions`). Create and edit pages override `handleRecordCreation/Update` to call the action; `App\Filament\Support\FormErrors::rethrow()` puts an action's refusal under the field or in a notice.
- **Actions:** `app/Actions/Catalogue/` `SaveProduct` (pictures in display order, each a new upload or a kept path; new ones re-encoded before the transaction, removed ones deleted after it), `DeleteProduct` (soft, refused while an auction of it is open). `app/Actions/Auctions/` `SaveAuction` (draft; editing a rejected one makes it a draft), `SubmitAuction`, `WithdrawAuction` (back to draft), `ApproveAuction` (real schedule; copies anti-sniping settings and `fee_snapshot`), `RejectAuction` (reason), `CancelAuction` (not live). Shared checks and the `auctions` activity log are in `Concerns\AuctionRules`. Lock order: company row, then the auction.
- **Rules:** the whole team drafts, submits and withdraws; only the owner cancels an approved (scheduled) auction; platform staff and the system owner review (`AuctionPolicy::review`). Submitting needs a verified, unsuspended company on a plan that `allowsSelling()`, a product with a picture, a start ahead (within `max_lead_days`) and a duration within `auction.listing`, and room in the plan's active-auction cap (`AuctionStatus::active()`: under review, scheduled, live; counted under the company lock). An auto-approved company goes straight to scheduled. A product has one unfinished auction at a time, cannot be listed once sold, and its details are frozen while an auction of it is active (`Product::isLocked()`; lists preload `is_locked`).
- **Admin review queue** (`app/Filament/Admin/Resources/Auctions/`): tabs by status, opening on "Awaiting review" (navigation badge); approve with the proposed times prefilled (a passed start defaults to now with the same duration), reject with a reason, cancel a scheduled auction with a reason (`cancellation_reason`). `App\Filament\Support\AuctionDetails` is the auction view in both panels.

### Bidding and closing

- **`App\Services\Bidding\ProxyResolver`** is pure (no database, no clock; `tests/Unit/Services/ProxyResolverTest.php` is the table of cases). Every bidder has a maximum (a manual bid's is its amount); the higher maximum leads at one increment above the other (never above its own), equal maximums keep the earlier leader, and a leading maximum that covers the reserve lifts the price to the reserve. The leader can only raise their own maximum (automatic bid), not bid by hand against themselves.
- **`App\Actions\Bidding\PlaceBid`**: buyers only (`AuctionPolicy::bid`: active, buyer, verified email), `auction.bidding.bids_per_minute`, an idempotency key minted with the form (`idempotency_keys`, claimed first inside the transaction; a duplicate gets the stored `BidResult`), one transaction retried on deadlock. Lock order: auction row, then the bidder's and leader's wallets (`Ledger::lock`), then the auction's bid hold. The end time is checked inside the transaction. The maximum may use at most `biddable_share_bps` of available balance plus what this auction already holds for the bidder. **The leader holds their whole maximum** (one active bid hold per auction, moved on a lead change or a raise). A bid within `anti_snipe_window_seconds` of the end moves it to now + `anti_snipe_extend_seconds`, at most `anti_snipe_max_extensions` times (`original_ends_at` keeps the first end). `auto_bids` rows are private; one stays active only while its owner leads.
- **`BuyNow`**: only while `bid_count` is 0; the full price must be available (the biddable share does not apply); sells at once (`close_reason` buy_now).
- **`auctions:sweep`** (scheduled every five seconds) runs `StartAuction` for due scheduled auctions and queues `CloseAuctionJob` (unique per auction) for ended live ones. **`CloseAuction`** re-checks under the lock (still live, end passed), so a stale or second closer does nothing: sold (bids and reserve met; leader wins at the current price) or unsold (no bids / reserve not met; hold released). Auto-bids are deactivated.
- **`App\Actions\Orders\OpenOrder`** (inside the caller's transaction): order `ORD-000123`, commission from the auction's `fee_snapshot`, `ship_by_at`, a `created` order event, and a `SaleCapture` ledger transaction (buyer → escrow, idempotent `sale:{auction}`, capturing the bid hold, whose whole amount is freed). Commission leaves escrow when the order completes (Phase 7).
- `CancelAuction` also cancels a live auction (owner while it has no bids; staff always, with a reason) and releases the leader's hold.
- **Buyer pages:** the bid form, automatic-bid toggle, buy now and watchlist button on `Auctions\Show` (a buyer's own maximum is shown only to them); `/my-bids` (`Account\Bids`: bidding, won, not won, watchlist).

### What is left of v1

Only the Breeze password-reset controllers (`PasswordResetLinkController`, `NewPasswordController`, which still email a link) and the `dropdown`, `dropdown-link`, `input-error` and `modal` components, restyled. Everything else has been replaced.

## Tests

`tests/Feature/Models/` covers the role rules, the database-enforced constraints and every factory; `tests/Feature/Seeders/DemoSeederTest.php` checks that the demo data covers every auction status and that its ledger balances; `tests/Feature/Panels/PanelAccessTest.php` covers who may open which panel. `tests/Feature/Auth/` covers the code service, sign-up, two-step sign-in, trusted devices and email verification; `tests/Feature/Account/` the step-up gate, the security settings and the buyer overview; `tests/Feature/Auctions/` the list filters, the detail page, products, the listing and review actions, bidding, closing, buy now and the bidding pages (`tests/Unit/Services/ProxyResolverTest.php` the proxy cases); `PublicPagesTest` renders every public page against the demo data. Tests that go through an emailed code call `Notification::fake()` and use the `Tests\Support\InteractsWithOtp` trait (`emailedCode()`, `withConfirmedOtp()`). `tests/Feature/Billing/` and `tests/Feature/Companies/` cover plans, invoices, renewals, the team and company administration, and `tests/Feature/Panels/` the panel access and the seller and admin pages, products, auctions and the review queue included (Livewire tests; `Tests\Support\InteractsWithCompanies`); `tests/Feature/Wallet/` covers the ledger, holds, fees, deposits, withdrawals, the gateway drivers (`Http::fake`), the callback routes, the wallet pages and `ledger:verify`; money tests use `Tests\Support\InteractsWithLedger` (`createPlatformAccounts()`, `fund()`, and `assertLedgerBalanced()` at the end). RefreshDatabase wraps each test in a transaction, so true concurrency tests need committed data and parallel processes. When adding a model, add its factory to the data provider in `FactoryTest`.

# Auction marketplace

An online auction marketplace. Seller companies subscribe to a plan and list items from their own panel. Buyers sign up for free and bid in real time from a wallet. Platform staff review listings and handle orders, disputes and payouts from an admin panel.

## What it does

- **Buyers** sign up and sign in with an emailed code, or with Google. They pass an identity check before bidding.
  - Bidding: by hand or automatically (proxy bidding) from a wallet, with live prices, a countdown and anti-sniping.
  - Money: buyers add money through SSLCommerz or Stripe and withdraw to a bank, bKash, Nagad or Rocket.
  - After the sale: orders with escrow, delivery tracking, disputes and reviews.
- **Sellers** (companies) work in `/seller`:
  - their plan and billing, team, products and auction drafts
  - orders, wallet and payouts, coupons and featured listings
  - reports, and a public storefront
- **Platform staff** work in `/admin`:
  - review queues for auctions, identity checks, orders, disputes, withdrawals and support tickets
  - accounts, fees, plans, coupons, notices and site pages
  - reports, the activity log and API oversight
- **Money** goes through a double-entry ledger. Every movement is a balanced transaction, and `php artisan ledger:verify` checks the books daily. Platform fees include VAT, and invoices and receipts are numbered PDFs.
- **Also included:** English and Bangla, an installable app with web push, a versioned REST API (`/api/v1`, docs at `/docs/api`), and a buyer data export.

## Stack

Laravel 13 (PHP 8.4), Livewire 4, Filament 5, Tailwind CSS 4, Vite, Laravel Reverb (websockets) and MySQL 8.4.

## Run it locally

You need PHP 8.4 (with the `gd`, `exif`, `zip` and `intl` extensions), Composer, Node 20+ and MySQL 8.4.

```
composer install
npm install
cp .env.example .env
php artisan key:generate
# create the databases first: CREATE DATABASE auction; CREATE DATABASE auction_test;
php artisan migrate --seed      # system owner, plans, fees, categories + demo data
php artisan storage:link
npm run build
composer dev                    # web server, queue workers, Reverb, scheduler and Vite together
```

Then open http://localhost:8000. Demo sign-ins:

| Role | Email | Password |
|---|---|---|
| System owner | `admin@auction.com` | `admin` |
| Platform staff | `staff@auction.com` | `password` |
| Seller (company owner) | `owner@dhaka-antiques.test` | `password` |
| Buyer | `buyer1@auction.com` … `buyer12@auction.com` | `password` |

With `MAIL_MAILER=log`, emailed codes go to `storage/logs/laravel.log`. In the `local` environment the code is also shown on the code page.

## Checks

```
composer check        # code style (Pint), static analysis (Larastan) and the tests
php artisan test      # tests only; they use the separate auction_test database
php artisan bids:load-test --buyers=10 --bids=20   # concurrent bids at one auction (local only; use a scratch database)
```

## Going live

Read [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). It covers the server, environment, workers, scheduler, backups, alerts and the release checklist.

## For developers

`CLAUDE.md` describes the architecture and the conventions:
- money as integers through `App\Support\Money`
- business operations as action classes
- the ledger's lock order
- policies for every model
- where each feature lives

Read it before changing the code.

## Security

Report a vulnerability privately to the system owner's address configured for your installation, not in a public issue.

# Deploying the auction marketplace

This guide is for whoever puts the marketplace on a server and keeps it running. It assumes a Linux server (Ubuntu 24.04 or similar) with root access. The application also runs on shared hosting without long-running processes, with the limits noted under "Without long-running processes".

## 1. What the server needs

- **PHP 8.4**, with these extensions:
  - `bcmath`, `ctype`, `curl`, `dom`, `exif`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`
  - plus `opcache`
- **MySQL 8.4.** The schema uses generated columns, so MariaDB and SQLite are not supported. Install the `mysqldump` client too; backups use it.
- **Nginx** (or Apache) with HTTPS. The site must run over HTTPS: sessions, web push and the installable app need it.
- **Node 20+**, to build the assets. You can build on a CI machine instead and upload `public/build`.
- **Supervisor** (or systemd), to keep the queue workers and the websocket server running.
- **Outgoing mail** through a real SMTP provider. Sign-in codes are mailed, so without mail nobody can sign in.

## 2. Environment (`.env`)

Start from `.env.example`. The settings that matter in production:

| Setting | Value |
|---|---|
| `APP_ENV` | `production`. **Never `local` on a server**: in `local` the sign-in code is shown on the page and the fake payment gateway works. |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-domain` |
| `APP_KEY` | `php artisan key:generate` once. Keep it: it encrypts payout destinations, gateway data and the trusted-browser cookies. |
| `SESSION_SECURE_COOKIE` | `true` |
| `DB_*` | the production database and a user with rights on it only |
| `MAIL_*`, `MAIL_FROM_ADDRESS` | your SMTP provider |
| `AUCTION_OWNER_EMAIL` / `AUCTION_OWNER_PASSWORD` | the system owner account created by the first seed. Change the password after the first sign-in. |
| `PAYMENT_GATEWAYS` | `sslcommerz,stripe` (never `fake`; it is refused outside `local`/`testing` anyway) |
| `SSLCOMMERZ_*`, `STRIPE_*` | live credentials. `SSLCOMMERZ_SANDBOX=false`. In the Stripe dashboard, add the webhook `https://your-domain/payments/stripe/notify` and copy its secret into `STRIPE_WEBHOOK_SECRET`. |
| `BROADCAST_CONNECTION` | `reverb` (own websocket server) or `pusher` (hosted) |
| `REVERB_*` / `VITE_REVERB_*` | the public host and port of the websocket server (see section 5) |
| `VAPID_*` | web push keys: `php artisan webpush:vapid` once |
| `GOOGLE_CLIENT_ID` / `_SECRET` | optional. The "Continue with Google" button appears only when they are set. |
| `ALERTS_EMAIL` | where failed jobs, failed backups and ledger problems are mailed |
| `BACKUP_PATH`, `BACKUP_KEEP_DAYS` | where backups go (default `storage/app/backups`) and how long they are kept |
| `TRUSTED_PROXIES` | only behind a load balancer or CDN: its addresses, or `*` if only the proxy can reach the server. Otherwise every visitor gets the proxy's IP, which breaks rate limits, fraud checks and the IP blacklist. |

The `VITE_*` values are compiled into the JavaScript, so set them **before** `npm run build`.

## 3. First installation

```
git clone <repository> /var/www/auction && cd /var/www/auction
git checkout v2                # or the release branch
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env           # then fill it in (section 2)
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=PlatformSeeder --force   # system owner, ledger accounts, plans, fees, categories; never the demo data
php artisan storage:link
composer docs                  # API documentation at /docs/api
php artisan optimize           # caches config, routes, views and events
```

File permissions: the web server user must be able to write `storage/` and `bootstrap/cache/`.

Nginx serves `public/`. Use the standard Laravel configuration (`try_files $uri $uri/ /index.php?$query_string;`). Allow uploads up to about 20 MB (`client_max_body_size 20m;`, plus `upload_max_filesize` and `post_max_size` in PHP) for product pictures and identity documents.

## 4. Processes that must keep running

| Process | Command | Why |
|---|---|---|
| Default queue worker | `php artisan queue:work --queue=default --tries=3 --max-time=3600` | mails, notifications, auction closing, saved-search alerts |
| Broadcasts worker | `php artisan queue:work --queue=broadcasts --tries=3 --max-time=3600` | live prices and toasts |
| Websocket server | `php artisan reverb:start --host=0.0.0.0 --port=8080` | the live bidding room (not needed with `pusher`) |
| Scheduler | cron: `* * * * * cd /var/www/auction && php artisan schedule:run >> /dev/null 2>&1` | starting and closing auctions (every 5 s through `auctions:sweep`), order deadlines, plan renewals, backups, `ledger:verify`, pruning |

An example Supervisor program (one per worker):

```
[program:auction-default]
command=php /var/www/auction/artisan queue:work --queue=default --tries=3 --max-time=3600
user=www-data
autostart=true
autorestart=true
stopwaitsecs=60
stdout_logfile=/var/www/auction/storage/logs/worker-default.log
```

Auctions close on time only while the scheduler and the default worker run. Check both after every restart.

## 5. Websockets behind Nginx

Point `REVERB_HOST` at your domain, with `REVERB_PORT=443` and `REVERB_SCHEME=https`. Then proxy `/app` and `/apps` to the Reverb port:

```
location ~ ^/apps? {
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_pass http://127.0.0.1:8080;
}
```

## 6. Backups

`backup:run` runs every night at 02:30. It writes two files to `BACKUP_PATH` and deletes copies older than `BACKUP_KEEP_DAYS`:
- `db-YYYY-MM-DD-HHMMSS.sql.gz`: the whole database
- `files-….zip`: uploaded pictures and identity documents

**Copy that folder off the server every day** (rsync, object storage, …). A backup on the same disk does not survive the disk.

To run one by hand: `php artisan backup:run` (`--no-files` for the database only).

To restore:
```
php artisan down
gunzip -c db-….sql.gz | mysql -u USER -p DATABASE
unzip files-….zip -d /tmp/restore
# copy /tmp/restore/public/* into storage/app/public/ and /tmp/restore/kyc/* into storage/app/private/kyc/
php artisan ledger:verify
php artisan up
```

Rehearse a restore on a spare server once before launch.

## 7. Monitoring

- **Health check:** `GET /up` answers 200 while the application boots and reaches its dependencies. Point an uptime monitor at it.
- **Alerts:** a failed queue job, a failed backup or a `ledger:verify` problem is logged and mailed to `ALERTS_EMAIL`, at most once every ten minutes per subject.
- **Logs:** `storage/logs/laravel.log`. Rotate it (set `LOG_STACK=daily` or use logrotate).
- **Failed jobs:** `php artisan queue:failed` lists them and `php artisan queue:retry all` retries them.
- **The books:** `ledger:verify` runs daily at 03:00. Any problem it finds is critical; look at it before anything else.

## 8. Releasing an update

```
php artisan down --render="errors::503"
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
composer docs
php artisan optimize
php artisan queue:restart        # workers pick up the new code
# restart Reverb through Supervisor
php artisan up
```

## 9. Without long-running processes (shared hosting)

- Use `BROADCAST_CONNECTION=pusher` with a Pusher (or compatible) account instead of Reverb.
- Run the scheduler from cron every minute. It starts `auctions:sweep`, which then repeats every five seconds within that minute.
- Run one queue worker from cron each minute: `php artisan queue:work --stop-when-empty --queue=broadcasts,default`. Mails and auction closing can then lag by up to a minute.

## 10. Before launch: checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, HTTPS everywhere.
- [ ] The demo seeder was **not** run. The system owner's password was changed after the first sign-in.
- [ ] Live gateway credentials. A small real deposit and withdrawal tested end to end, and the Stripe webhook shows as delivered.
- [ ] Mail delivers, including to Gmail and Outlook (SPF and DKIM set up for the sender domain).
- [ ] The scheduler, both workers and Reverb are running. A test auction started and closed on time.
- [ ] `ALERTS_EMAIL` set and tested with a deliberate failure (for example, run `php artisan backup:run` with a wrong `BACKUP_MYSQLDUMP`).
- [ ] A backup was copied off the server and a restore was rehearsed.
- [ ] The terms, privacy, FAQ and about pages were written in admin → Site pages.
- [ ] Branding (colour, logo, favicon) set in admin → Branding.
- [ ] `composer audit` and `npm audit` are clean.

# Deploying

What it takes to run the app somewhere real, for a feedback round and beyond. The server setup itself
(Docker Compose or Forge on Hetzner) will get its own section once it's chosen; everything here applies
either way.

## What runs

| Part | What it does | Notes |
|---|---|---|
| The Laravel app (PHP 8.2+) | Pages and the API | Needs the `pgsql` PHP extension |
| PostgreSQL 16+ | All the data | Back it up (see below) |
| Reverb (`php artisan reverb:start`) | Live player view | A long-running process: keep it running with Supervisor, systemd or a container |
| A proxy with HTTPS (e.g. Caddy) | Certificates, and forwarding `/app` and `/apps` to Reverb | Caddy gets and renews certificates by itself |

No queue worker or scheduler is needed yet: broadcasts and emails are sent straight away.

## Before the first deploy (yours to do)

1. **Domain.** Point an `A` record at the server's IP (and `AAAA` for IPv6 if it has one).
2. **Email provider** (Resend, Postmark, Mailgun or SES). Add your domain there and create the DNS records it
   gives you (SPF, DKIM, usually a DMARC record too), so emails arrive in inboxes rather than spam. New accounts
   can't save anything until they've clicked the link in the verification email, so this has to work.
3. **Sentry (optional).** Create a Laravel project at sentry.io and copy its DSN.
4. **Feedback.** Decide where feedback goes: a form (Tally, Google Forms…) or an email address.

## Settings

Copy [deploy/env.production.example](../deploy/env.production.example) to `.env` on the server and fill in
everything marked `CHANGE ME`. The things that are easy to get wrong:

- `APP_URL` must be the real `https://` address. Links in emails and invites are built from it.
- `VITE_REVERB_*` are built into the JavaScript, so they must be right before `npm run build`. The browser
  connects to `wss://<VITE_REVERB_HOST>:443/app`, which the proxy forwards to Reverb.
- `REVERB_HOST`/`REVERB_PORT` are where the app itself sends broadcasts: Reverb inside the server, over plain
  http, not the public address.
- `APP_DEBUG=false`. Error pages with debug on can show secrets.

## First deploy

```bash
git clone https://github.com/Jdolan182/ttrpg-tracker.git && cd ttrpg-tracker
cp deploy/env.production.example .env   # then fill it in
composer install --no-dev --optimize-autoloader
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=SrdCreatureSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Then start Reverb (`php artisan reverb:start`) under your process manager, and point the web server at
`public/`.

## Updating

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=SrdCreatureSeeder --force   # safe to re-run
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan reverb:restart
php artisan up
```

## The proxy

With Caddy, something like this (adjust the PHP side to however the app is served):

```caddy
theturnkeeper.app {
    # Live player view: websockets to Reverb.
    @reverb path /app /app/* /apps /apps/*
    reverse_proxy @reverb 127.0.0.1:8080

    root * /path/to/ttrpg-tracker/public
    php_fastcgi 127.0.0.1:9000
    file_server
    encode gzip
}
```

## Checking it works

- `https://<domain>/up` answers "Application up".
- Sign up with a real address: the verification email arrives, and its link opens the real domain.
- Run a campaign fight with a second browser on the campaign page: it updates within a second. If it only
  updates every 5 seconds, browsers can't reach Reverb: check the proxy and `VITE_REVERB_*` (then rebuild).

## Testers

- Give friends the higher limits with `php artisan plan:set friend@example.com pro`.
- Their feedback arrives wherever `FEEDBACK_URL` points (or by email to `CONTACT_EMAIL` when it's empty); errors they hit show up in Sentry.

## Backups

Back up the database daily, e.g. a cron job running
`pg_dump -Fc turnkeeper > /backups/turnkeeper-$(date +%F).dump`, kept for a couple of weeks, with a copy off the
server (Hetzner Storage Box, or Hetzner's own server backups). Users can also download their own backups from
the Encounters page.

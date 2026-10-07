# Deploying

How Turnkeeper runs at https://theturnkeeper.app: one Hetzner server running Ubuntu 24.04, set up by
[deploy/server/setup.sh](../deploy/server/setup.sh) and updated with
[deploy/server/deploy.sh](../deploy/server/deploy.sh). Both were tried end to end on a fresh Ubuntu 24.04.

## What runs

| Part | What it does | Set up as |
|---|---|---|
| Caddy | HTTPS (gets and renews certificates itself), serves the app, forwards `/app` and `/apps` to Reverb | `/etc/caddy/Caddyfile`, from [deploy/server/Caddyfile](../deploy/server/Caddyfile) |
| PHP 8.4 (FPM) | The Laravel app, running as the `turnkeeper` user | pool from [php-fpm-pool.conf](../deploy/server/php-fpm-pool.conf) |
| PostgreSQL 18 | All the data | database and user `turnkeeper` |
| Reverb | Live player view (websockets), on 127.0.0.1:8080 only | `turnkeeper-reverb` systemd service |
| Backups | `pg_dump` every night at 03:15, kept 14 days | `/etc/cron.d/turnkeeper-backup`, files in `/var/backups/turnkeeper` |

The firewall only lets in SSH, 80 and 443. Security updates install themselves, and fail2ban blocks
repeated failed SSH logins. No queue worker or scheduler is needed yet: broadcasts and emails are sent
straight away.

## Before the first deploy

1. **Server.** In Hetzner Cloud, create an Ubuntu 24.04 server (the smallest shared-CPU one, e.g. CX22, is plenty
   for testers) and add your SSH key. Turning on Hetzner's own backups (20% of the server price) gives you
   copies off the server.
2. **DNS.** Where you bought the domain, add `A` records for `theturnkeeper.app` and `www.theturnkeeper.app`
   pointing at the server's IPv4 address (and `AAAA` records for its IPv6 address, if you like). `.app` sites
   only open over https, so the site won't load until the server has its certificate, which Caddy gets
   once these records point at it.
3. **Email (Resend).** In Resend: Domains → Add domain → `theturnkeeper.app`, and add the DNS records it
   shows you (they prove the domain is yours and keep the emails out of spam). Wait for it to say Verified.
   Then API Keys → Create, with "Sending access" for that domain. That key is `MAIL_PASSWORD` below. New
   accounts can't save anything until they've clicked their verification email, so this has to work.
4. **Optional:** a Sentry project for error reports (copy its DSN), and a feedback form. Without a form, "Send
   feedback" emails `CONTACT_EMAIL`.

## First deploy

SSH in as root and run:

```bash
git clone https://github.com/Jdolan182/ttrpg-tracker.git /root/turnkeeper-setup
bash /root/turnkeeper-setup/deploy/server/setup.sh
```

It takes about five minutes. It creates `/var/www/turnkeeper/.env` from
[deploy/env.production.example](../deploy/env.production.example) with the database password and Reverb
keys already filled in. Then fill in the mail settings (and anything optional):

```bash
sudo -u turnkeeper nano /var/www/turnkeeper/.env
#   MAIL_HOST=smtp.resend.com
#   MAIL_USERNAME=resend
#   MAIL_PASSWORD=<the Resend API key>
sudo -u turnkeeper php /var/www/turnkeeper/artisan config:cache
```

Settings are cached, so run `config:cache` after any change to `.env`. Changes to a `VITE_*` setting need
a full deploy, since those are built into the JavaScript.

Things that are easy to get wrong:

- Mail uses port 587. Hetzner blocks outgoing 25 and 465 on new servers, so those fail without an error.
- `TRUSTED_PROXIES` stays empty: Caddy hands requests straight to PHP with the visitor's real address.
  Trusting forwarded headers would let anyone fake their address and dodge the rate limits.
- `APP_DEBUG=false`. Error pages with debug on can show secrets.

## Updating

```bash
sudo -u turnkeeper bash /var/www/turnkeeper/deploy/server/deploy.sh
```

It shows a "back shortly" page, pulls the latest code, installs, builds, migrates, re-seeds the SRD monsters,
caches and restarts Reverb, then puts the site back. If a step fails, the site stays in maintenance mode so
nothing half-updated is served. Fix the problem and run it again, or `php artisan up` to bring it back as it is.

## Checking it works

- `https://theturnkeeper.app/up` answers "Application up".
- Sign up with a real address: the verification email arrives, and its link opens the real domain.
- Run a campaign fight with a second browser on the campaign page: it updates within a second. If it only
  updates every 5 seconds, browsers can't reach Reverb: check `systemctl status turnkeeper-reverb` and the
  `VITE_REVERB_*` settings (then deploy again to rebuild).

Logs: the app's in `/var/www/turnkeeper/storage/logs`, Caddy's in `/var/log/caddy/turnkeeper.log`, and
`journalctl -u turnkeeper-reverb` for Reverb.

## You and your testers

- Sign up on the site like anyone else; the server has no built-in accounts.
- Give yourself and friends the higher limits:
  `sudo -u turnkeeper php /var/www/turnkeeper/artisan plan:set friend@example.com pro`.
- Their feedback arrives wherever `FEEDBACK_URL` points (or by email to `CONTACT_EMAIL` when it's empty); errors
  they hit show up in Sentry.

## How it's being used

Totals (accounts, what's been made, active users), never anyone's content:

```bash
ssh turnkeeper "sudo -u turnkeeper php /var/www/turnkeeper/artisan turnkeeper:stats"
```

To browse the data in HeidiSQL or similar, use the read-only login made by
[deploy/server/readonly-db-user.sh](../deploy/server/readonly-db-user.sh). It can't see passwords, sessions or
reset tokens, and can't change anything. Open a tunnel with `ssh -N turnkeeper-db` and leave it running, then
connect the app to PostgreSQL at `127.0.0.1`, port `5433`, user `turnkeeper_readonly`, database `turnkeeper`.
Its password: `ssh turnkeeper "cat /root/turnkeeper-readonly-password"`.

## Backups

Every night at 03:15 the database is saved to `/var/backups/turnkeeper` (14 days kept). Those copies are on
the same server, so also turn on Hetzner's server backups, or copy the folder to a Storage Box. To restore one:

```bash
sudo -u postgres pg_restore --clean --if-exists -d turnkeeper /var/backups/turnkeeper/turnkeeper-<date>.dump
```

Users can also download their own backups from the Encounters page.

#!/usr/bin/env bash
# One-time setup of a fresh Hetzner Ubuntu 24.04 server for Turnkeeper. Run it as root:
#
#     git clone https://github.com/Jdolan182/ttrpg-tracker.git /root/turnkeeper-setup
#     bash /root/turnkeeper-setup/deploy/server/setup.sh
#
# Installs PHP, Postgres, Node and Caddy (HTTPS), creates the app's user and database, puts the
# app in /var/www/turnkeeper, keeps Reverb running and backs the database up nightly. Safe to run
# again: it skips what's already done and never overwrites .env or the database.
# Afterwards, fill in the mail settings in /var/www/turnkeeper/.env (see docs/deploying.md).
set -euo pipefail

DOMAIN=theturnkeeper.app
# Where the code comes from; overridable for trying the script out against a local copy.
REPO="${REPO:-https://github.com/Jdolan182/ttrpg-tracker.git}"
APP_USER=turnkeeper
APP_DIR=/var/www/turnkeeper
DB_NAME=turnkeeper
PHP=8.4
NODE_MAJOR=22
PG=18

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
step() { printf '\n\033[1;31m==> %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run this as root."; exit 1; }
export DEBIAN_FRONTEND=noninteractive

step "System updates and basics"
apt-get update -q
apt-get -yq upgrade
apt-get install -yq ca-certificates curl gnupg git unzip ufw fail2ban unattended-upgrades \
    software-properties-common debian-keyring debian-archive-keyring apt-transport-https
# Security updates install themselves.
dpkg-reconfigure -f noninteractive unattended-upgrades

step "Firewall: only SSH and the web"
# By port, not the OpenSSH profile, which only exists once openssh-server is installed.
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

step "PHP $PHP"
add-apt-repository -y ppa:ondrej/php
apt-get update -q
apt-get install -yq "php$PHP-fpm" "php$PHP-cli" "php$PHP-pgsql" "php$PHP-mbstring" "php$PHP-xml" \
    "php$PHP-curl" "php$PHP-zip" "php$PHP-bcmath" "php$PHP-intl"
if ! command -v composer >/dev/null; then
    curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

step "Node $NODE_MAJOR (to build the frontend)"
if ! node --version 2>/dev/null | grep -q "^v$NODE_MAJOR\."; then
    curl -fsSL "https://deb.nodesource.com/setup_$NODE_MAJOR.x" | bash -
    apt-get install -yq nodejs
fi

step "PostgreSQL $PG"
if [ ! -f /etc/apt/sources.list.d/pgdg.list ]; then
    install -d /usr/share/postgresql-common/pgdg
    curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo "$VERSION_CODENAME")-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list
    apt-get update -q
fi
apt-get install -yq "postgresql-$PG"
systemctl enable --now postgresql

step "Caddy (web server, gets HTTPS certificates itself)"
if [ ! -f /etc/apt/sources.list.d/caddy-stable.list ]; then
    curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -q
fi
apt-get install -yq caddy
systemctl enable --now caddy

step "The app's user and database"
id "$APP_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$APP_USER"
DB_PASSWORD=""
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname = '$APP_USER'" | grep -q 1; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    sudo -u postgres psql -qc "CREATE ROLE $APP_USER LOGIN PASSWORD '$DB_PASSWORD'"
    sudo -u postgres createdb -O "$APP_USER" "$DB_NAME"
fi

step "The app in $APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then
    install -d -o "$APP_USER" -g "$APP_USER" "$APP_DIR"
    sudo -u "$APP_USER" git clone -q "$REPO" "$APP_DIR"
fi
cd "$APP_DIR"
if [ ! -f .env ]; then
    sudo -u "$APP_USER" cp deploy/env.production.example .env
    random() { openssl rand -hex 16; }
    sed -i \
        -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD/" \
        -e "s/^REVERB_APP_ID=.*/REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)/" \
        -e "s/^REVERB_APP_KEY=.*/REVERB_APP_KEY=$(random)/" \
        -e "s/^REVERB_APP_SECRET=.*/REVERB_APP_SECRET=$(random)/" \
        .env
    [ -n "$DB_PASSWORD" ] || echo "!! The database user already existed, so DB_PASSWORD in .env needs filling in by hand."
fi
# Only the app reads it: it holds the database password and keys.
chown "$APP_USER:$APP_USER" .env
chmod 600 .env

step "PHP for the app (runs as $APP_USER, talks to Caddy over a socket)"
sed -e "s/{{PHP}}/$PHP/g" -e "s/{{APP_USER}}/$APP_USER/g" "$HERE/php-fpm-pool.conf" > "/etc/php/$PHP/fpm/pool.d/turnkeeper.conf"
# Bigger pages and uploads (backups) than PHP's defaults, and keep compiled code in memory.
cat > "/etc/php/$PHP/fpm/conf.d/90-turnkeeper.ini" <<'INI'
upload_max_filesize = 8M
post_max_size = 8M
memory_limit = 256M
opcache.enable = 1
opcache.memory_consumption = 128
INI
systemctl enable "php$PHP-fpm"
systemctl restart "php$PHP-fpm"

step "Build and start the app"
# The app's own copy: deploy.sh works in the folder it's in, and $APP_USER can't read /root.
sudo -u "$APP_USER" bash "$APP_DIR/deploy/server/deploy.sh" --first

step "Reverb (live player view), kept running by systemd"
sed -e "s/{{PHP}}/$PHP/g" -e "s/{{APP_USER}}/$APP_USER/g" -e "s#{{APP_DIR}}#$APP_DIR#g" "$HERE/turnkeeper-reverb.service" \
    > /etc/systemd/system/turnkeeper-reverb.service
systemctl daemon-reload
systemctl enable --now turnkeeper-reverb

step "Caddy site for $DOMAIN"
sed -e "s/{{DOMAIN}}/$DOMAIN/g" -e "s#{{APP_DIR}}#$APP_DIR#g" "$HERE/Caddyfile" > /etc/caddy/Caddyfile
systemctl reload-or-restart caddy

step "Nightly database backups (kept 14 days in /var/backups/turnkeeper)"
install -m 755 "$HERE/backup.sh" /usr/local/bin/turnkeeper-backup
echo "15 3 * * * root /usr/local/bin/turnkeeper-backup $DB_NAME" > /etc/cron.d/turnkeeper-backup
systemctl enable --now cron

step "Done"
cat <<DONE
Next:
  1. Fill in the mail settings in $APP_DIR/.env (MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD), and
     optionally FEEDBACK_URL, CONTACT_EMAIL and SENTRY_LARAVEL_DSN. Then, as $APP_USER:
         cd $APP_DIR && php artisan config:cache
  2. Open https://$DOMAIN (the DNS A record must point here first) and check https://$DOMAIN/up.
  3. Sign up as yourself, then give your account the higher limits:
         sudo -u $APP_USER php $APP_DIR/artisan plan:set you@example.com pro
To update later: sudo -u $APP_USER bash $APP_DIR/deploy/server/deploy.sh
DONE

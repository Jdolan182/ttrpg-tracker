#!/usr/bin/env bash
# Puts the latest code live. Run as the app's user on the server:
#
#     sudo -u turnkeeper bash /var/www/turnkeeper/deploy/server/deploy.sh
#
# setup.sh runs it once with --first (nothing to pull, no maintenance page, a new app key).
set -euo pipefail

# All in a function, so bash has read the whole script before `git pull` can replace this file.
main() {
    cd "$(dirname "${BASH_SOURCE[0]}")/../.."
    local first=false
    [ "${1:-}" = "--first" ] && first=true

    if ! $first; then
        # Visitors see "back shortly" rather than a half-updated app. If a step fails it stays that
        # way, so nothing half-done is served: fix it and run this again (or `php artisan up`).
        php artisan down --retry=15
        trap 'echo "!! Deploy failed; the site is still in maintenance mode."' ERR
        git pull --ff-only
    fi

    composer install --no-dev --optimize-autoloader --no-interaction --quiet
    grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

    # VITE_* settings from .env are built into the JavaScript here.
    npm ci --no-audit --no-fund --loglevel=error
    npm run build

    php artisan migrate --force
    # The SRD monsters: safe to re-run, and updates them in place.
    php artisan db:seed --class=SrdCreatureSeeder --force

    php artisan optimize

    if ! $first; then
        # Reverb picks up the new code: it stops, and systemd starts it again.
        php artisan reverb:restart
        php artisan up
    fi

    echo "Deployed $(git log -1 --format='%h %s')"
}

main "$@"
exit

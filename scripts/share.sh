#!/usr/bin/env bash
# Shares the local app through a temporary Cloudflare quick tunnel: a random
# https://….trycloudflare.com link, no account needed. Run from WSL with Sail up:
#
#   ./scripts/share.sh         start sharing (Ctrl+C to stop)
#   ./scripts/share.sh stop    stop a share running in the background
#
# While sharing it turns debug mode off (error pages can reveal secrets), trusts the tunnel's
# proxy headers so links come out as https://, and serves built assets instead of the Vite dev
# server, which visitors can't reach. .env is put back exactly as it was when sharing stops.
# The tunnel's output, including the link, also goes to storage/logs/share.log.
set -euo pipefail
cd "$(dirname "$0")/.."

if [[ "${1:-}" == "stop" ]]; then
    # Stopping the tunnel lets the running script finish and restore .env.
    if pkill -INT -x cloudflared; then echo "Stopping the share…"; else echo "Nothing is being shared."; fi
    exit 0
fi

CLOUDFLARED=$(command -v cloudflared || echo "$HOME/.local/bin/cloudflared")
[[ -x "$CLOUDFLARED" ]] || { echo "cloudflared isn't installed (expected it in ~/.local/bin)."; exit 1; }
pgrep -x cloudflared > /dev/null && { echo "Already sharing. Stop it first with: ./scripts/share.sh stop"; exit 1; }

PORT=$(grep -E '^APP_PORT=' .env | cut -d= -f2 || true)
PORT=${PORT:-80}
curl -fsS -o /dev/null "http://localhost:${PORT}/up" || { echo "The app isn't answering on port ${PORT}. Start it with ./vendor/bin/sail up -d"; exit 1; }

cp .env .env.before-share
restore() {
    trap - EXIT INT TERM HUP
    [[ -f .env.before-share ]] && mv .env.before-share .env
    echo "Sharing stopped. Your settings are back as they were."
}
trap restore EXIT INT TERM HUP

set_env() {
    if grep -qE "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
set_env APP_DEBUG false
set_env TRUSTED_PROXIES '*'
set_env SHARE_MODE true

echo "Building assets…"
./vendor/bin/sail npm run build > /dev/null

echo "Starting the tunnel. Your link appears below as https://…trycloudflare.com"
echo "Anyone with the link can use the app while this runs."
"$CLOUDFLARED" tunnel --no-autoupdate --url "http://localhost:${PORT}" 2>&1 | tee storage/logs/share.log

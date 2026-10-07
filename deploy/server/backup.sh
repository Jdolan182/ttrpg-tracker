#!/usr/bin/env bash
# Nightly database backup, installed by setup.sh as /usr/local/bin/turnkeeper-backup and run from
# /etc/cron.d/turnkeeper-backup. Keeps 14 days on the server; for a copy off it, turn on Hetzner's
# server backups or copy /var/backups/turnkeeper to a Storage Box.
#
# Restore one with:  sudo -u postgres pg_restore --clean --if-exists -d turnkeeper /var/backups/turnkeeper/<file>.dump
set -euo pipefail

DB="${1:-turnkeeper}"
DIR=/var/backups/turnkeeper
install -d -m 700 -o postgres -g postgres "$DIR"

FILE="$DIR/$DB-$(date +%F).dump"
# Written by root (this runs as root), so only root can read the backups.
# shellcheck disable=SC2024
sudo -u postgres pg_dump -Fc "$DB" > "$FILE.tmp"
mv "$FILE.tmp" "$FILE"
chmod 600 "$FILE"

find "$DIR" -name "$DB-*.dump" -mtime +14 -delete

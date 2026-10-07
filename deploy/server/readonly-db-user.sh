#!/usr/bin/env bash
# A read-only database login for browsing the live data in a desktop app (HeidiSQL, TablePlus…)
# through an SSH tunnel; the database itself is never open to the internet. Run as root on the
# server, and again after migrations add tables:
#
#     bash /var/www/turnkeeper/deploy/server/readonly-db-user.sh
#
# It can read everything except secrets: password hashes and "remember me" tokens, login sessions,
# password reset tokens, and the cache and job tables. It can't change anything. The password is
# made on the first run and kept in /root/turnkeeper-readonly-password (never printed).
set -euo pipefail

SECRET=/root/turnkeeper-readonly-password
sql() { sudo -u postgres psql -v ON_ERROR_STOP=1 -qAt -d turnkeeper "$@"; }

if ! sql -c "SELECT 1 FROM pg_roles WHERE rolname = 'turnkeeper_readonly'" | grep -q 1; then
    password="$(openssl rand -hex 20)"
    sql -c "CREATE ROLE turnkeeper_readonly LOGIN PASSWORD '$password'"
    install -m 600 /dev/null "$SECRET"
    echo "$password" > "$SECRET"
fi

sql <<'SQL'
GRANT CONNECT ON DATABASE turnkeeper TO turnkeeper_readonly;
GRANT USAGE ON SCHEMA public TO turnkeeper_readonly;
-- Even a stray UPDATE typed into the app fails.
ALTER ROLE turnkeeper_readonly SET default_transaction_read_only = on;

DO $grant$
DECLARE
    t text;
BEGIN
    FOR t IN
        SELECT tablename FROM pg_tables WHERE schemaname = 'public'
            AND tablename NOT IN ('users', 'sessions', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs')
    LOOP
        EXECUTE format('GRANT SELECT ON public.%I TO turnkeeper_readonly', t);
    END LOOP;

    -- Accounts, but not their password hashes or "remember me" tokens.
    EXECUTE (
        SELECT 'GRANT SELECT (' || string_agg(quote_ident(column_name), ', ') || ') ON public.users TO turnkeeper_readonly'
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'users' AND column_name NOT IN ('password', 'remember_token')
    );
END
$grant$;
SQL

echo "turnkeeper_readonly is ready. Its password: sudo cat $SECRET"

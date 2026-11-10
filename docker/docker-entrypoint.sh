#!/bin/sh
# Container entrypoint.
#
# Everything expensive — Composer, importmap, asset-map:compile, cache:warmup —
# now happens at build time. What is left here is only the work that genuinely
# needs the runtime environment: config sanity checks, volume permissions, and
# optionally migrations. Keeping it this short is what makes restarts fast and
# makes a failed deploy fail during `docker build` rather than in front of users.
set -e

cd /var/www/html

# --- Fail fast on missing configuration -------------------------------------
#
# An unset variable in docker-compose interpolation becomes an *empty string* in
# the container, not an absent one — and Symfony's Dotenv treats an empty env var
# as already set, so it never falls back to the value in .env. The failure modes
# are both indirect: an empty APP_SECRET silently breaks CSRF tokens, session
# signing and the remember-me cookie, while an empty DATABASE_URL surfaces as a
# driver exception deep inside the first request. Refuse to start instead.
if [ -z "${APP_SECRET:-}" ]; then
    echo "FATAL: APP_SECRET is empty. Set it in this service's Coolify environment." >&2
    exit 1
fi

if [ -z "${DATABASE_URL:-}" ]; then
    echo "FATAL: DATABASE_URL is empty. Set it in this service's Coolify environment." >&2
    exit 1
fi

# Checked for the same reason, even though nothing crashes without it: an empty
# APP_URL produces email whose links go nowhere, and that failure is invisible
# until someone reports a dead button in a password-reset message.
if [ -z "${APP_URL:-}" ]; then
    echo "FATAL: APP_URL is empty. Set it to this deployment's own URL" >&2
    echo "       (e.g. https://zenoo.example.com), not necessarily the final domain." >&2
    exit 1
fi

# --- Normalise empty optional variables -------------------------------------
#
# This is the only place these defaults exist. Coolify deploys this repository
# with the Dockerfile build pack and injects the environment itself, so there is
# no Compose file whose `${VAR:-default}` could catch a variable left blank in
# the Coolify UI — it arrives as an empty string. Symfony treats an empty env var
# as set, so it never falls back to .env either, and the value simply stays empty
# all the way down to the code that uses it.
#
# An empty MAILER_DSN is the sharp one: Transport::fromDsn('') throws "The mailer
# DSN must contain a scheme", which kills the Messenger worker on the first queued
# message. `null://null` is the explicit way to say accept-and-discard.
if [ -z "${MAILER_DSN:-}" ]; then
    export MAILER_DSN='null://null'
fi

if [ -z "${SESSION_DSN:-}" ]; then
    export SESSION_DSN='file:///var/www/html/var/sessions'
fi

if [ -z "${MESSENGER_TRANSPORT_DSN:-}" ]; then
    export MESSENGER_TRANSPORT_DSN='doctrine://default?auto_setup=0'
fi

# Say it out loud rather than letting mail vanish silently — that is the kind of
# thing that gets diagnosed as a broken form days later.
case "$MAILER_DSN" in
    null://*)
        echo "NOTE: MAILER_DSN is null:// — outbound email is accepted and DISCARDED, not sent."
        ;;
esac

# Checkout needs both Stripe variables. Without them the buy button shows "payments
# are temporarily unavailable" instead of taking money it cannot record.
if [ -z "${STRIPE_SECRET_KEY:-}" ] || [ -z "${STRIPE_WEBHOOK_SECRET:-}" ]; then
    echo "NOTE: STRIPE_SECRET_KEY and/or STRIPE_WEBHOOK_SECRET is empty — checkout is OFF."
else
    case "$STRIPE_SECRET_KEY" in
        sk_test_*|rk_test_*) echo "NOTE: Stripe is in TEST mode — no real money is taken." ;;
    esac
fi

if [ -z "${ANTHROPIC_API_KEY:-}" ]; then
    echo "NOTE: ANTHROPIC_API_KEY is empty — the chat (/chat) is OFF."
fi

echo "Session storage: ${SESSION_DSN}"

# --- Wait for the database --------------------------------------------------
#
# Coolify starts this container without waiting for a database that lives in a
# separate resource, so on a host reboot the app frequently comes up first.
# Without this the migration below just fails and the container exits, and while
# `restart: unless-stopped` does eventually win, it does so through a crash loop
# that looks exactly like a broken deploy in the logs.
# Where we are connecting, with the user and password hidden, so the logs can show it safely.
db_target=$(printf '%s' "$DATABASE_URL" | sed -E 's#^([a-z0-9+]+)://[^@/]*@#\1://***:***@#')
echo "Waiting for the database at ${db_target}"
tries=0
until db_error=$(php bin/console dbal:run-sql "SELECT 1" --no-ansi 2>&1); do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "FATAL: database still unreachable after 60s. Check DATABASE_URL." >&2
        echo "       Connecting to: ${db_target}" >&2
        # The driver's own message says what is wrong: unknown host, access denied,
        # unknown database, connection refused...
        reason=$(printf '%s' "$db_error" | tr '\n' ' ' | grep -oE 'SQLSTATE\[[^"]*' | head -n 1 | sed -E 's/[[:space:]]+/ /g; s/[\ ]+$//')
        echo "       Reason: ${reason:-$(printf '%s' "$db_error" | tail -n 3 | tr '\n' ' ')}" >&2
        exit 1
    fi
    sleep 2
done
echo "Database is up."

# --- Database schema --------------------------------------------------------
#
# Runs on every boot. `--allow-no-migration` makes that a no-op once the schema
# is current, so the cost of a plain restart is one query.
#
# This is what makes a deploy hands-off, and it has to happen here rather than in
# a post-deploy step: supervisor does not start until this script exec's, so the
# schema is always current before the first request or the Messenger worker
# touches it. That ordering matters because MESSENGER_TRANSPORT_DSN uses
# auto_setup=0 — the messenger_messages table comes from a migration
# (Version20261101120000), not on demand, so a worker started against an
# unmigrated database crash-loops.
#
# Set RUN_MIGRATIONS=false to take back manual control; a failed migration stops
# the container, which is deliberate. Coolify keeps the previous one serving
# rather than promoting a release whose schema did not apply.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Running database migrations..."
    if ! php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; then
        echo "" >&2
        echo "FATAL: migrations failed — refusing to start, so the previous release" >&2
        echo "       keeps serving instead of one whose schema did not apply." >&2
        echo "" >&2
        echo "If the error is 'table already exists', the schema is fine and it is the" >&2
        echo "ledger that is wrong: this database already carried the tables (a restored" >&2
        echo "dump, or an earlier deployment) while doctrine_migration_versions does not" >&2
        echo "record the migrations that created them, so Doctrine is replaying them." >&2
        echo "Reconcile the ledger — do not edit or delete the migrations:" >&2
        echo "" >&2
        echo "  1. Set RUN_MIGRATIONS=false in Coolify and redeploy, so this boots." >&2
        echo "  2. php bin/console doctrine:migrations:status" >&2
        echo "  3. php bin/console doctrine:schema:validate" >&2
        echo "  4. If the schema already matches the entity mapping, record every" >&2
        echo "     migration as applied WITHOUT running it:" >&2
        echo "       php bin/console doctrine:migrations:version --add --all --no-interaction" >&2
        echo "     If it does not match, inspect the gap first:" >&2
        echo "       php bin/console doctrine:schema:update --dump-sql" >&2
        echo "  5. Set RUN_MIGRATIONS back to true and redeploy." >&2
        exit 1
    fi
fi

# --- Course lessons ---------------------------------------------------------
#
# Loads the 30-lesson ZENOO Dog Wellness Course for every language that doesn't
# have its course yet (English, Lithuanian, Ukrainian, Polish), as drafts. Courses
# that exist are left alone, so edits made in the Course studio are never
# overwritten. Failure is reported, not fatal.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php bin/console app:course:import --missing --no-interaction \
        || echo "WARNING: course import failed — run 'php bin/console app:course:import' by hand." >&2
fi

# --- Admin bootstrap ---------------------------------------------------------
#
# Only for the very first deploy against an empty database, and only when both
# variables are set — leave them unset and this is skipped entirely.
#
# app:create-admin-user exits non-zero when the address is already taken, which
# is the normal case on every boot after the first, so the failure is reported
# rather than propagated. Remove these two variables from the Coolify
# environment once the account exists: keeping a password there serves no
# purpose, since the command will not update an account that already exists.
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    echo "Ensuring admin user ${ADMIN_EMAIL} exists..."
    php bin/console app:create-admin-user "$ADMIN_EMAIL" "$ADMIN_PASSWORD" \
        || echo "  (not created — the account already exists)"
fi

# --- Writable paths ---------------------------------------------------------
#
# public/uploads is a Docker volume; var/ holds the cache
# warmed as root during the build. PHP-FPM and the Messenger worker both run as
# www-data. var/sessions is only used if SESSION_DSN is switched back to a
# file:// DSN, but it costs nothing to keep it writable and ready.
#
# This is the safety net for the one thing that reliably goes wrong with
# container storage: a volume that arrives owned by root. A *named* volume
# inherits ownership from the image, so it is already correct — but a host bind
# mount (which is what Coolify's Persistent Storage UI creates if you give it a
# host path) does not, and neither does a volume restored from a backup. Running
# this as root on every boot costs a moment and makes all of those cases work.
php bin/console app:init-upload-dirs --no-interaction
chown -R www-data:www-data var public/uploads
chmod 700 var/sessions

# Hand off to supervisor, which runs nginx + PHP-FPM + the Messenger worker.
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

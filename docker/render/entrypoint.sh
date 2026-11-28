#!/bin/sh
#
# Boot script for the single-container Render deployment.
#
# A free Render service has NO shell access and NO one-off jobs, so every step
# that would normally be a manual release command has to happen here instead, on
# every container start. Each step is idempotent, and a spin-down simply
# recreates the container from this image and runs this script again.
#
set -eu

# --- Public address ---------------------------------------------------------
# Render injects PORT (the only port it routes to us) and, once the service has
# a hostname, RENDER_EXTERNAL_URL. Deriving APP_URL from the latter removes a
# chicken-and-egg secret: the hostname does not exist until after the first
# deploy.
PORT="${PORT:-10000}"
APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-http://localhost:${PORT}}}"
export PORT APP_URL

# The session cookie and Sanctum's stateful-domain list must name the public
# host, or the browser refuses the cookie and every authenticated request 419s.
APP_HOST="$(printf '%s' "$APP_URL" | sed -e 's#^[a-z][a-z0-9+.-]*://##' -e 's#/.*$##')"
SESSION_DOMAIN="${SESSION_DOMAIN:-$APP_HOST}"
SANCTUM_STATEFUL_DOMAINS="${SANCTUM_STATEFUL_DOMAINS:-$APP_HOST}"
export SESSION_DOMAIN SANCTUM_STATEFUL_DOMAINS

# --- nginx ------------------------------------------------------------------
# Only $PORT is substituted. The template also contains nginx variables such as
# $uri and $document_root, which must survive untouched - hence the explicit
# variable list rather than a bare envsubst.
envsubst '$PORT' \
    < /etc/nginx/templates/default.conf.template \
    > /etc/nginx/conf.d/default.conf

cd /var/www/html

# --- Database ---------------------------------------------------------------
# Rows live in an external MySQL-compatible database so they survive a restart;
# the uploaded invoice CSVs do not (there is no free persistent disk).
# Skipped when DB_HOST is unset, so the very first deploy still boots before the
# database credentials have been filled in.
if [ -n "${DB_HOST:-}" ] && [ "${SKIP_MIGRATIONS:-false}" != "true" ]; then
    attempt=1
    until php artisan migrate --force; do
        if [ "$attempt" -ge 5 ]; then
            echo "[entrypoint] migrations failed after $attempt attempts" >&2
            exit 1
        fi
        echo "[entrypoint] migrate attempt $attempt failed, retrying in 5s" >&2
        attempt=$((attempt + 1))
        sleep 5
    done
else
    echo "[entrypoint] DB_HOST is empty - skipping migrations"
fi

# --- Contracts --------------------------------------------------------------
# The audit engine only runs overcharge rules when a contract is linked to the
# invoice, and there is no contracts UI/API to create one in-app yet. So seed a
# default contract + rate card for every organization/carrier. Idempotent
# (updateOrCreate) and safe on every boot; skippable with SKIP_SEEDING=true.
if [ -n "${DB_HOST:-}" ] && [ "${SKIP_SEEDING:-false}" != "true" ]; then
    php artisan db:seed --class=ContractsSeeder --force
fi

# --- Caches -----------------------------------------------------------------
# `route:cache` is deliberately NOT run: /api/v1/health is defined with a
# closure (api/routes/api.php) and route caching cannot serialise closures.
php artisan config:cache
php artisan view:cache

# The commands above ran as root, so the files they just wrote (the config
# cache, the compiled Blade views) are root-owned. Hand the writable trees back
# to the web user, or php-fpm can no longer recompile a view or append a log.
chown -R www-data:www-data storage bootstrap/cache

echo "[entrypoint] starting php-fpm + nginx"
exec supervisord -c /etc/supervisor/rateguard.conf

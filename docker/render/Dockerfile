# syntax=docker/dockerfile:1

##############################################################################
# RateGuard - production image for the single-container Render deployment.
#
# Why one container: the free Render plan exposes exactly ONE port and grants
# no shell access, so nginx + php-fpm + the built React SPA ship in a single
# image and are supervised together. There is also no way to run a one-off
# command, so migrations and config caching happen in the entrypoint
# (docker/render/entrypoint.sh) on every container boot.
#
# Build context is the REPOSITORY ROOT - see `dockerContext` in render.yaml.
##############################################################################

##############################################################################
# Stage 1 - build the React SPA
##############################################################################
FROM node:22-bookworm-slim AS spa

WORKDIR /build

# Manifests before sources, so the npm layer survives source-only edits.
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./

# package.json -> "build": "tsc -b && vite build" -> /build/dist
RUN npm run build


##############################################################################
# Stage 2 - runtime: php-fpm + nginx
##############################################################################
FROM php:8.3-fpm-bookworm AS app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# --- System packages --------------------------------------------------------
# gettext-base supplies envsubst, which renders the nginx template against
# Render's $PORT at boot.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        gettext-base \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ---------------------------------------------------------
# Deliberately narrower than docker/php/Dockerfile: gd, exif and pdo_pgsql are
# not referenced anywhere under api/app, so building them would only slow the
# deploy down. The rest match the dev image.
RUN docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip

RUN pecl install redis \
    && docker-php-ext-enable redis

# --- PHP config -------------------------------------------------------------
# Same upload ceiling as the dev image (5 MB, matching the client-side guard).
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# php-fpm pool sized for the 512 MB free instance; it must also not scrub the
# container environment that Laravel reads its configuration from.
COPY docker/render/php-fpm.conf /usr/local/etc/php-fpm.d/zz-render.conf

# --- Composer ---------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Application ------------------------------------------------------------
WORKDIR /var/www/html
COPY api/ .

RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# The built SPA gets its own root, so nginx can serve /assets/* as immutable
# without routing it through Laravel's public/ directory.
COPY --from=spa /build/dist /var/www/spa

# --- Container wiring -------------------------------------------------------
# The supervisor config is self-contained and lives OUTSIDE /etc/supervisor/conf.d
# on purpose: the Debian package's default supervisord.conf globs that directory
# in, and would otherwise load these sections a second time.
COPY docker/render/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/render/supervisord.conf    /etc/supervisor/rateguard.conf
COPY docker/render/entrypoint.sh       /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && rm -f /etc/nginx/sites-enabled/default \
    && mkdir -p storage/framework/cache/data \
                storage/framework/sessions \
                storage/framework/views \
                storage/logs \
                bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Documentation only: Render routes traffic to whatever PORT it injects.
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

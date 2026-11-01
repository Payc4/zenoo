# Single-container image: nginx + PHP-FPM + the Messenger worker, supervised as
# one process tree. This is the shape Coolify deploys best — one service, one
# health check, no inter-container networking to configure.
#
# PHP 8.2 matches composer.json's `"php": ">=8.2"` platform constraint; nothing
# in composer.lock needs anything newer.
FROM php:8.2-fpm

# System packages and PHP extensions.
#
# GD has its codecs configured in explicitly. `docker-php-ext-install gd` with no
# preceding `docker-php-ext-configure` produces a PNG-only build: the JPEG and
# WebP functions are never *registered*, so calling one is a fatal error that no
# `@` can suppress, and it only shows up in the container — never on a dev box,
# where PHP ships GD with the full codec set. Profile photo uploads need all of
# JPEG, PNG and WebP.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libxml2-dev \
        libzip-dev \
        nginx \
        poppler-utils \
        procps \
        supervisor \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    # ext-redis backs the session handler. Symfony's SessionHandlerFactory needs
    # either this extension or predis/predis to open a redis:// DSN, and fails at
    # runtime with "Cannot find the redis extension" if neither is present.
    # `yes ''` accepts pecl's defaults for the igbinary/lzf/zstd prompts; the
    # pipeline still exits with pecl's own status, so a genuine failure fails the
    # build.
    && yes '' | pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Fail the build, not the first upload, if those codecs ever drop out again.
RUN php -r 'foreach (["imagecreatefromjpeg","imagejpeg","imagecreatefromwebp","imagewebp"] as $f) { if (!function_exists($f)) { fwrite(STDERR, "GD is missing $f - check docker-php-ext-configure gd\n"); exit(1); } }'

# Likewise for ext-redis: without it every request that touches the session dies,
# and that is a worse place to discover the extension is missing than here.
RUN php -r 'if (!extension_loaded("redis")) { fwrite(STDERR, "ext-redis is not loaded - sessions use a redis:// DSN\n"); exit(1); }'

# PHP and PHP-FPM tuning. Both filenames start with zz- so they load last and
# win over the base image's defaults.
COPY docker/php/zz-overrides.ini /usr/local/etc/php/conf.d/zz-overrides.ini
COPY docker/php-fpm/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

# Pinned to the 2.x line rather than `latest`, so a new Composer major release
# cannot change how an unrelated deploy builds.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Dependencies as their own layer, before the source is copied: Composer only
# re-runs when composer.json/composer.lock actually change, so an ordinary code
# push reuses the cached vendor tree instead of re-downloading every package.
#
# --no-autoloader because --optimize-autoloader has to scan src/ to build the
# classmap, and src/ does not exist yet at this point.
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist

# Application source.
COPY . .

# Scripts stay ENABLED for this one command, on purpose. symfony/runtime
# generates vendor/autoload_runtime.php from Composer's post-autoload-dump
# event, and public/index.php require()s that file — with --no-scripts here the
# image builds cleanly and then 500s on every request. composer.json's
# auto-scripts (cache:clear, assets:install, importmap:install) hang off
# post-install-cmd instead, so they do not fire here.
RUN composer dump-autoload --no-dev --optimize --no-interaction

ENV APP_ENV=prod \
    APP_DEBUG=0

# Assets and the production cache are compiled into the image rather than on
# every container start: boots are fast, a broken asset build fails the deploy
# instead of the site, and the running container needs no outbound network
# (ZENOO uses only local JS and CSS, so importmap:install has nothing to fetch).
#
# These boot the Symfony kernel, which resolves the %env()% placeholders from
# the committed .env. No database is contacted — Doctrine connections are lazy —
# so the placeholder DATABASE_URL in .env is fine here. cache:warmup is also
# what writes the Doctrine entity proxies, since config/packages/doctrine.yaml
# sets auto_generate_proxy_classes: false for prod.
RUN php bin/console assets:install public --no-interaction \
    && php bin/console importmap:install --no-interaction \
    && php bin/console asset-map:compile --no-interaction \
    && php bin/console cache:warmup --no-interaction

# Paths written at runtime.
#
# Creating them here is what makes the volume mounts work: Docker seeds a
# *named* volume from whatever is at the mount point in the image, ownership
# included. If the directory does not exist in the image, the volume is created
# empty and owned by root, and PHP-FPM (running as www-data) cannot write to it.
RUN mkdir -p \
        var/cache var/log var/sessions \
        public/uploads/profiles \
    && chown -R www-data:www-data var public/uploads \
    && chmod 700 var/sessions

# nginx. The shipped config targets `app:9000` so the two-container dev
# docker-compose.yaml can mount it as-is; in this image nginx and PHP-FPM share
# a network namespace, so the upstream is rewritten to the loopback address.
RUN rm -f /etc/nginx/sites-enabled/default \
    && rm -rf /etc/nginx/conf.d/* \
    && mkdir -p /etc/nginx/conf.d /var/log/nginx \
    && cp /var/www/html/docker/nginx/default.conf /etc/nginx/conf.d/default.conf \
    && sed -i 's/fastcgi_pass app:9000/fastcgi_pass 127.0.0.1:9000/' /etc/nginx/conf.d/default.conf

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY docker/check-messenger.sh /usr/local/bin/check-messenger.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh /usr/local/bin/check-messenger.sh \
    && mkdir -p /var/log/supervisor /run

EXPOSE 80

# Declared here rather than in a compose file: Coolify deploys this repository
# with the Dockerfile build pack, so docker-compose.prod.yaml is never read and a
# health check defined there would silently not exist. /health touches the
# database, so this also catches a container that booted but lost its DB.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://localhost/health || exit 1

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

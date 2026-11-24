# syntax=docker/dockerfile:1

#-----------------------------------------------------------------------------------------------------------------------
# Composer dependencies
#-----------------------------------------------------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./

# config/VssCfg.php is in the autoload "files" list, so it must exist for the autoloader to be dumped
COPY config/ ./config/

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --ignore-platform-req=ext-gd \
        --ignore-platform-req=ext-curl \
        --ignore-platform-req=ext-pdo_mysql \
    && composer clear-cache

#-----------------------------------------------------------------------------------------------------------------------
# Runtime: php-fpm image, which also ships the `php` CLI used by cron and the Discord threads
#-----------------------------------------------------------------------------------------------------------------------
FROM php:8.4-fpm AS runtime

ENV DEBIAN_FRONTEND=noninteractive

# - imagemagick / ffmpeg : media previews
# - procps               : `ps`, used to prevent parallel runs of the same command and to kill hung conversions
# - mariadb-client       : db-init.sh and sql/data/load.sh (provides both `mariadb` and `mysql`)
# - libzip/libpng/...    : build deps of the gd extension
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        imagemagick \
        ffmpeg \
        procps \
        ca-certificates \
        mariadb-client \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
    && apt-get purge -y libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# supercronic runs the handful of genuinely scheduled jobs (see docker/cron/schedule.cron). Unlike cron it runs as
# an unprivileged foreground process, takes a crontab without a user column, and logs to stdout, which is what a
# container wants. The long-running work is not scheduled at all any more: it runs as the worker-* services.
ARG SUPERCRONIC_VERSION=v0.2.51
ARG SUPERCRONIC_SHA256=cc232108374e8e2b537045f2d5eaa1229f619db314c3ba6a14d38b35dc0aa123
ADD --checksum=sha256:${SUPERCRONIC_SHA256} \
    https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-amd64 \
    /usr/local/bin/supercronic
RUN chmod 0755 /usr/local/bin/supercronic

# Harden ImageMagick: token metadata is untrusted media. Only the raster coders MediaTool forces are allowed,
# so a file can never be read as SVG / MVG / MSL / TEXT / URL and reference local files or internal services.
COPY docker/imagemagick/policy.xml /etc/ImageMagick-7/policy.xml

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-cryptonoises.ini

WORKDIR /opt/cryptonoises

COPY --from=vendor /app/vendor/ ./vendor/
COPY . .

# Env-driven config. These two files read every setting from the environment at runtime, so they can be baked in:
# config/*.php is gitignored and therefore absent from the build context (see .dockerignore).
# Bind-mounting your own file over config/consts-secrets.php or config/environment.php replaces it.
COPY docker/config/consts-secrets.php docker/config/environment.php ./config/

COPY docker/bin/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/bin/wait-for-db   /usr/local/bin/wait-for-db
COPY docker/bin/cn-cron       /usr/local/bin/cn-cron
COPY docker/bin/run-scheduler /usr/local/bin/run-scheduler
RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/wait-for-db /usr/local/bin/cn-cron /usr/local/bin/run-scheduler \
    && chmod +x commands/run commands/run-tezos bin/discord-* docker/bin/* \
    && mkdir -p log external-ipfs external-storage \
    && chown -R www-data:www-data /opt/cryptonoises/log /opt/cryptonoises/external-ipfs /opt/cryptonoises/external-storage

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

#!/usr/bin/env bash
#
# Prepares the writable directories and takes a snapshot of the environment for cron jobs, then hands over
# to the service command (dropping privileges when asked).

set -euo pipefail

APP_DIR=/opt/cryptonoises

# config/consts-secrets.php and config/environment.php are baked into the image (see the Dockerfile) and read
# their values from the environment, so nothing has to be installed here.
#
# The steps below need root. `docker compose run -u www-data php ...` runs this script as www-data, which is fine:
# the volumes are already prepared by the long-running services, and a one-off command does not need cron's
# environment snapshot.

if [ "$(id -u)" = "0" ]; then
    #-------------------------------------------------------------------------------------------------------------------
    # Writable directories (named volumes are created owned by root)

    for dir in log external-ipfs external-storage; do
        mkdir -p "$APP_DIR/$dir"
        chown www-data:www-data "$APP_DIR/$dir" 2>/dev/null || true
    done

    #-------------------------------------------------------------------------------------------------------------------
    # Environment snapshot for cron
    #
    # cron runs jobs with an almost empty environment, so the config files would see no variables at all.
    # `export -p` writes correctly quoted assignments that docker/bin/cn-cron sources before running a command.

    mkdir -p /run/cryptonoises
    export -p > /run/cryptonoises/cron-env
    chmod 600 /run/cryptonoises/cron-env
fi

#-----------------------------------------------------------------------------------------------------------------------
# Hand over to the service command
#
# php-fpm and cron have to start as root (php-fpm drops its workers to www-data itself, cron runs each job through
# cn-cron, which drops privileges). Everything else - the Discord threads - asks for the drop with
# CN_DROP_PRIVILEGES=1 rather than compose `user:`, because the setup above needs to write config/ as root.

if [ "${CN_DROP_PRIVILEGES:-0}" = "1" ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
fi

exec "$@"

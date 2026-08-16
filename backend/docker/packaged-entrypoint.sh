#!/usr/bin/env sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Copy .env.packaged.example to .env.packaged and set APP_KEY before starting packaged mode." >&2
    exit 1
fi

mkdir -p \
    storage/app/private \
    storage/app/artwork \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

rm -f bootstrap/cache/*.php

if [ "$(id -u)" = "0" ] \
    && [ -n "${SONOTHEQUE_HOST_UID:-}" ] \
    && [ -n "${SONOTHEQUE_HOST_GID:-}" ]; then
    owner_marker="storage/.sonotheque-owner-${SONOTHEQUE_HOST_UID}-${SONOTHEQUE_HOST_GID}"
    if [ ! -f "$owner_marker" ]; then
        chown -R "${SONOTHEQUE_HOST_UID}:${SONOTHEQUE_HOST_GID}" storage
        touch "$owner_marker"
        chown "${SONOTHEQUE_HOST_UID}:${SONOTHEQUE_HOST_GID}" "$owner_marker"
    fi
    chown -R "${SONOTHEQUE_HOST_UID}:${SONOTHEQUE_HOST_GID}" bootstrap/cache

    if [ -S /var/run/docker.sock ]; then
        /bin/setpriv \
            --reuid "$SONOTHEQUE_HOST_UID" \
            --regid "$SONOTHEQUE_HOST_GID" \
            --groups "${SONOTHEQUE_DOCKER_GID:-0}" \
            php artisan package:discover --ansi
        exec /bin/setpriv \
            --reuid "$SONOTHEQUE_HOST_UID" \
            --regid "$SONOTHEQUE_HOST_GID" \
            --groups "${SONOTHEQUE_DOCKER_GID:-0}" \
            "$@"
    fi

    /bin/setpriv \
        --reuid "$SONOTHEQUE_HOST_UID" \
        --regid "$SONOTHEQUE_HOST_GID" \
        --clear-groups \
        php artisan package:discover --ansi
    exec /bin/setpriv \
        --reuid "$SONOTHEQUE_HOST_UID" \
        --regid "$SONOTHEQUE_HOST_GID" \
        --clear-groups \
        "$@"
fi

php artisan package:discover --ansi

exec "$@"

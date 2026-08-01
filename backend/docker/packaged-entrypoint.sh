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

php artisan package:discover --ansi

exec "$@"

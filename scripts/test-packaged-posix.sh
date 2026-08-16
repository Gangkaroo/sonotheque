#!/bin/sh

set -eu

REPOSITORY_ROOT=$(CDPATH='' cd "$(dirname "$0")/.." && pwd)
FIXTURE_ROOT="${TMPDIR:-/tmp}/sonotheque-posix-smoke-$$"

cleanup() {
    rm -rf "$FIXTURE_ROOT"
}
trap cleanup EXIT HUP INT TERM

mkdir -p \
    "$FIXTURE_ROOT/scripts/lib" \
    "$FIXTURE_ROOT/music/Archive" \
    "$FIXTURE_ROOT/music/Recent" \
    "$FIXTURE_ROOT/backup"
cp "$REPOSITORY_ROOT/.env.packaged.example" "$FIXTURE_ROOT/.env.packaged.example"
cp "$REPOSITORY_ROOT/compose.packaged.yaml" "$FIXTURE_ROOT/compose.packaged.yaml"
cp "$REPOSITORY_ROOT/scripts/packaged-config.php" "$FIXTURE_ROOT/scripts/packaged-config.php"
cp "$REPOSITORY_ROOT/scripts/system-backup-bundle.php" "$FIXTURE_ROOT/scripts/system-backup-bundle.php"
cp "$REPOSITORY_ROOT/scripts/lib/PackagedConfiguration.php" "$FIXTURE_ROOT/scripts/lib/PackagedConfiguration.php"
cp "$REPOSITORY_ROOT/scripts/lib/SystemBackupBundle.php" "$FIXTURE_ROOT/scripts/lib/SystemBackupBundle.php"

php "$FIXTURE_ROOT/scripts/packaged-config.php" init
php "$FIXTURE_ROOT/scripts/packaged-config.php" roots \
    --root "$FIXTURE_ROOT/music/Archive" \
    --root "$FIXTURE_ROOT/music/Recent"
php "$FIXTURE_ROOT/scripts/packaged-config.php" network \
    --address 127.0.0.1 --port 18082 --lan false --hostname ci
touch "$FIXTURE_ROOT/model.pb"
php "$FIXTURE_ROOT/scripts/packaged-config.php" audio-intelligence \
    --model "$FIXTURE_ROOT/model.pb" --accelerator cpu

grep -Fq 'APP_URL=http://127.0.0.1:18082' "$FIXTURE_ROOT/.env.packaged"
grep -Fq 'AUDIO_INTELLIGENCE_DRIVER=essentia_docker' "$FIXTURE_ROOT/.env.packaged"
grep -Fq 'target: /music/root-2' "$FIXTURE_ROOT/compose.packaged.override.yaml"

printf 'database' > "$FIXTURE_ROOT/backup/database.dump"
printf 'storage' > "$FIXTURE_ROOT/backup/storage.tar"
printf 'base64:key' > "$FIXTURE_ROOT/backup/app-key.txt"
php "$FIXTURE_ROOT/scripts/system-backup-bundle.php" create \
    --path="$FIXTURE_ROOT/backup" --mode=Packaged --database=sonotheque
php "$FIXTURE_ROOT/scripts/system-backup-bundle.php" validate \
    --path="$FIXTURE_ROOT/backup" --mode=Packaged
printf 'tampered' >> "$FIXTURE_ROOT/backup/database.dump"
if php "$FIXTURE_ROOT/scripts/system-backup-bundle.php" validate \
    --path="$FIXTURE_ROOT/backup" --mode=Packaged >/dev/null 2>&1; then
    printf 'Tampered backup unexpectedly passed validation.\n' >&2
    exit 1
fi

sh -n "$REPOSITORY_ROOT/sonotheque"
sh "$REPOSITORY_ROOT/sonotheque" help >/dev/null
docker compose \
    --env-file "$FIXTURE_ROOT/.env.packaged" \
    -f "$FIXTURE_ROOT/compose.packaged.yaml" \
    -f "$FIXTURE_ROOT/compose.packaged.override.yaml" \
    config --quiet

printf 'POSIX packaged smoke checks passed.\n'

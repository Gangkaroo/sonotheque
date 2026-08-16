#!/bin/sh

set -eu

REPOSITORY_ROOT=$(CDPATH='' cd "$(dirname "$0")/.." && pwd)
VERSION=${1:-$(tr -d '\r\n' < "$REPOSITORY_ROOT/VERSION")}
ARCHIVE_NAME="sonotheque-$VERSION-linux-macos-portable"
OUTPUT_ROOT="$REPOSITORY_ROOT/dist/releases"
ARCHIVE_PATH="$OUTPUT_ROOT/$ARCHIVE_NAME.tar.gz"
CHECKSUM_PATH="$ARCHIVE_PATH.sha256"
CONTENTS_PATH="${TMPDIR:-/tmp}/sonotheque-release-contents-$$"

cleanup() {
    rm -f "$CONTENTS_PATH"
}
trap cleanup EXIT HUP INT TERM

[ -f "$ARCHIVE_PATH" ] || { printf 'Release archive does not exist: %s\n' "$ARCHIVE_PATH" >&2; exit 1; }
[ -f "$CHECKSUM_PATH" ] || { printf 'Release checksum does not exist: %s\n' "$CHECKSUM_PATH" >&2; exit 1; }

(
    cd "$OUTPUT_ROOT"
    sha256sum -c "$(basename "$CHECKSUM_PATH")"
)
tar -tzf "$ARCHIVE_PATH" > "$CONTENTS_PATH"

for required in \
    "$ARCHIVE_NAME/sonotheque" \
    "$ARCHIVE_NAME/INSTALL.md" \
    "$ARCHIVE_NAME/docs/platform-support.md" \
    "$ARCHIVE_NAME/compose.packaged.yaml" \
    "$ARCHIVE_NAME/scripts/packaged-config.php" \
    "$ARCHIVE_NAME/scripts/lib/PackagedConfiguration.php" \
    "$ARCHIVE_NAME/scripts/system-backup-bundle.php" \
    "$ARCHIVE_NAME/scripts/lib/SystemBackupBundle.php" \
    "$ARCHIVE_NAME/backend/Dockerfile.packaged" \
    "$ARCHIVE_NAME/frontend/Dockerfile.packaged"
do
    grep -Fqx "$required" "$CONTENTS_PATH" \
        || { printf 'Release archive is missing: %s\n' "$required" >&2; exit 1; }
done

if grep -E '(^|/)\.git/|(^|/)\.env\.packaged$|(^|/)compose\.packaged\.override\.yaml$|(^|/)packaged-roots\.json$|(^|/)node_modules/|(^|/)vendor/|(^|/)backups/|(^|/)runtime-logs/|(^|/)AGENTS\.md$' "$CONTENTS_PATH" >/dev/null; then
    printf 'Release archive contains generated, private, or development-only files.\n' >&2
    exit 1
fi

mode=$(tar -tvzf "$ARCHIVE_PATH" "$ARCHIVE_NAME/sonotheque" | awk '{ print $1 }')
case "$mode" in
    *x*) ;;
    *) printf 'The sonotheque launcher is not executable in the archive.\n' >&2; exit 1 ;;
esac

printf 'Release verified: %s\n' "$ARCHIVE_PATH"

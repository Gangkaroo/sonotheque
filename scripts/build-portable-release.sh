#!/bin/sh

set -eu

REPOSITORY_ROOT=$(CDPATH='' cd "$(dirname "$0")/.." && pwd)
VERSION=${1:-$(tr -d '\r\n' < "$REPOSITORY_ROOT/VERSION")}

case "$VERSION" in
    ''|*[!0-9A-Za-z.-]*)
        printf 'Invalid release version: %s\n' "$VERSION" >&2
        exit 1
        ;;
esac

ARCHIVE_NAME="sonotheque-$VERSION-linux-macos-portable"
OUTPUT_ROOT="$REPOSITORY_ROOT/dist/releases"
STAGING_ROOT="$OUTPUT_ROOT/$ARCHIVE_NAME"
ARCHIVE_PATH="$OUTPUT_ROOT/$ARCHIVE_NAME.tar.gz"
CHECKSUM_PATH="$ARCHIVE_PATH.sha256"

rm -rf "$STAGING_ROOT"
rm -f "$ARCHIVE_PATH" "$CHECKSUM_PATH"
mkdir -p "$STAGING_ROOT"

cd "$REPOSITORY_ROOT"
git ls-files | while IFS= read -r relative_path; do
    case "$relative_path" in
        .agents/*|.codex/*|.github/*|.idea/*|backups/*|dist/*|runtime-logs/*|AGENTS.md|.env.packaged)
            continue
            ;;
    esac

    [ -f "$relative_path" ] || continue
    destination="$STAGING_ROOT/$relative_path"
    mkdir -p "$(dirname "$destination")"
    cp "$relative_path" "$destination"
done

# Keep local release verification useful before a new release file has been
# committed. Once tracked, these copies simply replace identical files.
for relative_path in \
    sonotheque \
    scripts/build-portable-release.sh \
    scripts/verify-portable-release.sh \
    scripts/test-packaged-posix.sh \
    scripts/packaged-config.php \
    scripts/system-backup-bundle.php \
    scripts/lib/PackagedConfiguration.php \
    scripts/lib/SystemBackupBundle.php \
    docs/platform-support.md
do
    [ -f "$relative_path" ] || continue
    destination="$STAGING_ROOT/$relative_path"
    mkdir -p "$(dirname "$destination")"
    cp "$relative_path" "$destination"
done

chmod +x "$STAGING_ROOT/sonotheque"
chmod +x "$STAGING_ROOT/backend/docker/packaged-entrypoint.sh"
chmod +x "$STAGING_ROOT/scripts/test-packaged-posix.sh"

tar -C "$OUTPUT_ROOT" -czf "$ARCHIVE_PATH" "$ARCHIVE_NAME"
(
    cd "$OUTPUT_ROOT"
    sha256sum "$(basename "$ARCHIVE_PATH")" > "$(basename "$CHECKSUM_PATH")"
)
rm -rf "$STAGING_ROOT"

printf 'Created release archive: %s\n' "$ARCHIVE_PATH"
printf 'Created SHA-256 file:  %s\n' "$CHECKSUM_PATH"

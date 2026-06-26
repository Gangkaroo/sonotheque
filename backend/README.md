# Music Library Backend

Laravel and API Platform backend for scanning local music folders, exposing the
catalog API, serving artwork, and streaming audio.

## Requirements

- PHP 8.5 with PostgreSQL extensions
- Composer
- Docker Desktop

## Local Setup

From this directory:

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan migrate
```

PostgreSQL is expected to run from the repository root via Docker Compose. The
default development connection is `127.0.0.1:5433`.

Run the API and queue worker in separate terminals. Prefer the PHP 8.5 binary if
an older PHP appears first on `PATH`:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work --tries=1 --timeout=1800
```

Queue a scan for a configured library-root ID:

```powershell
php artisan music:scan 1
```

For development or diagnostics, run it in the current process with
`php artisan music:scan 1 --sync`.

Each library root's `cover_image_path` is resolved relative to its album
folders. Valid JPEG, PNG, GIF, and WebP covers are cached under
`storage/app/artwork`, with a bounded WebP thumbnail generated during scans.
Embedded artwork is used as a fallback when the configured folder cover is
absent.

API documentation is available at `http://127.0.0.1:8000/api/docs`.

See `../docs/runtime.md` for the full startup, queue worker, scan
troubleshooting, and backup guide.

## Verification

```powershell
vendor/bin/pint --test
php artisan test
```

The tests use the isolated PostgreSQL database `music_library_test` on port `5433`.

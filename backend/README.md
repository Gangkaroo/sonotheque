# Sonotheque Backend

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

For daily use, start the complete stack from the repository root:

```powershell
.\scripts\start.ps1
.\scripts\status.ps1
```

Explicit LAN startup is available with `scripts\start.ps1 -Lan` after a
32-character-or-longer `SONOTHEQUE_ADMIN_TOKEN` is configured in `.env`.
See `../docs/runtime.md` for address selection, token verification, and the
narrow Windows Firewall rule.

For manual diagnostics, run the API and queue listener in separate terminals.
Prefer the PHP 8.5 binary if an older PHP appears first on `PATH`:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:listen --tries=1 --timeout=0 --memory=512 --sleep=1
```

Queue a scan for a configured library-root ID:

```powershell
php artisan music:scan 1
```

For development or diagnostics, run it in the current process with
`php artisan music:scan 1 --sync`.

Each library root has an ordered `cover_image_paths` list. Paths are resolved
from each album folder and may use parent segments as long as the result remains
inside the library root. Valid JPEG, PNG, GIF, and WebP covers are cached under
`storage/app/artwork`, with a bounded WebP thumbnail generated during scans.
Embedded artwork is used when no configured folder cover can be used.

API documentation is available at `http://127.0.0.1:8000/api/docs`.

See `../docs/runtime.md` for the full startup, queue worker, scan
troubleshooting, and backup guide.

## Verification

```powershell
vendor/bin/pint --test
composer check:autoload
php artisan test
```

PHP code follows PSR-12, enforced by the repository Pint configuration. PHP
namespaces, class names, and file locations follow the PSR-4 mappings in
`composer.json`.

The tests use the isolated PostgreSQL database `sonotheque_test` on port `5433`.

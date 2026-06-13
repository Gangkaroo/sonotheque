# Music Library Backend

Laravel and API Platform backend for scanning local music folders and exposing a read-only catalog API.

## Requirements

- PHP 8.5 with PostgreSQL extensions
- Composer
- Docker Desktop

## Local Setup

From the repository root, start PostgreSQL:

```powershell
docker compose up -d postgres
```

Then configure and migrate the backend:

```powershell
cd backend
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan migrate
```

Run the API and queue worker in separate terminals:

```powershell
composer serve
composer queue
```

Queue a scan for a configured library-root ID:

```powershell
php artisan music:scan 1
```

For development or diagnostics, run it in the current process with
`php artisan music:scan 1 --sync`.

API documentation is available at `http://127.0.0.1:8000/api/docs`.

## Verification

```powershell
vendor/bin/pint --test
php artisan test
```

The tests use the isolated PostgreSQL database `music_library_test` on port `5433`.

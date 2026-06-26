# Runtime Guide

This guide describes how to run the local music library during development on
Windows. The app is designed to run locally first: PostgreSQL runs in Docker,
while PHP/Laravel runs natively so the scanner can access folders on local and
external drives.

## Services

The development stack has four moving parts:

- PostgreSQL 18 in Docker, exposed on `127.0.0.1:5433`.
- Laravel API on `http://127.0.0.1:8000`.
- Laravel database queue worker for scans.
- Vue/Vite frontend on `http://127.0.0.1:5173`.

The frontend proxies `/api` requests to `http://127.0.0.1:8000`.

## Requirements

- Docker Desktop.
- PHP 8.5 with the PostgreSQL and GD extensions.
- Composer.
- Node.js 22.12 or newer.
- npm 10 or newer.

On this machine, an older XAMPP PHP can appear first on `PATH`. Use the PHP 8.5
binary explicitly when needed:

```powershell
$php85 = "C:\Users\Tom\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
& $php85 --version
```

## First-Time Setup

From the repository root:

```powershell
docker compose up -d postgres
```

Set up the backend:

```powershell
cd backend
Copy-Item .env.example .env
composer install
& $php85 artisan key:generate
& $php85 artisan migrate
```

Set up the frontend:

```powershell
cd ..\frontend
npm install
```

## Daily Startup

Start PostgreSQL:

```powershell
docker compose up -d postgres
docker inspect --format "{{.State.Health.Status}}" music-library-postgres
```

Start the backend API in one terminal:

```powershell
cd backend
& $php85 artisan serve --host=127.0.0.1 --port=8000
```

Start the queue worker in a second terminal:

```powershell
cd backend
& $php85 artisan queue:work --tries=1 --timeout=1800
```

Start the frontend in a third terminal:

```powershell
cd frontend
npm run dev -- --host 127.0.0.1
```

Open the app at:

```text
http://127.0.0.1:5173/
```

The API documentation is available at:

```text
http://127.0.0.1:8000/api/docs
```

## Background Scans

Scans are queued through the Laravel database queue. The queue worker must be
running for scans started from the Settings UI to progress.

For command-line diagnostics, queue a scan for a library-root ID:

```powershell
cd backend
& $php85 artisan music:scan 1
```

Run the scan in the current process when debugging scanner behavior:

```powershell
& $php85 artisan music:scan 1 --sync
```

Supported audio extensions are configured in `backend/config/music-library.php`.
At the moment they are:

```text
aac, aif, aiff, alac, flac, m4a, mp3, oga, ogg, opus, wav, wma
```

The expected library layout is:

```text
library-root/
`-- Artist/
    `-- Album/
        |-- configured-cover-path
        |-- 01 - Track.mp3
        `-- 02 - Track.flac
```

Cover paths are configured per library root and resolved relative to each album
folder. The configured folder cover is preferred. Embedded artwork is used as a
fallback.

## Runtime Logs

If services are started as background processes, write logs to `runtime-logs/`.
That folder is ignored by Git.

Useful files:

- `runtime-logs/backend-server.out.log`
- `runtime-logs/backend-server.err.log`
- `runtime-logs/queue-worker.out.log`
- `runtime-logs/queue-worker.err.log`
- `runtime-logs/frontend-vite.out.log`
- `runtime-logs/frontend-vite.err.log`

## Verification

Backend checks:

```powershell
cd backend
vendor/bin/pint --test
& $php85 artisan test
```

Frontend checks:

```powershell
cd frontend
npm run lint
npm run type-check
npm run test
npm run build
```

The backend test suite uses PostgreSQL database `music_library_test` on port
`5433`. Create it once if it does not exist:

```powershell
docker exec -it music-library-postgres createdb -U music_library music_library_test
```

## Troubleshooting

If the frontend loads but data does not appear, check the backend:

```powershell
Invoke-WebRequest -Uri http://127.0.0.1:8000/api/dashboard-metrics -UseBasicParsing
```

If scans stay queued, check that the queue worker is running. Then inspect
pending jobs:

```powershell
cd backend
& $php85 artisan queue:failed
```

If Docker is not reachable, start Docker Desktop and retry:

```powershell
docker version
docker compose up -d postgres
```

If the API cannot connect to PostgreSQL, verify that the container is healthy
and that `backend/.env` uses:

```text
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=music_library
DB_USERNAME=music_library
DB_PASSWORD=music_library
```

If the wrong PHP version is used, `php --version` may show the XAMPP binary.
Use `$php85` explicitly for Artisan commands.

If album covers do not update after changing files on disk, rescan the affected
library root. Cached artwork is stored under:

```text
backend/storage/app/artwork
```

## Backup And Recovery

This project is still early, but two things are worth preserving:

- PostgreSQL data: catalog metadata, library roots, favorites, playlists, scan
  history, and queue jobs.
- Artwork cache: generated and copied cover images under
  `backend/storage/app/artwork`.

Create a database dump:

```powershell
docker exec music-library-postgres pg_dump -U music_library music_library > music_library.sql
```

Restore a database dump into an empty database:

```powershell
Get-Content .\music_library.sql | docker exec -i music-library-postgres psql -U music_library music_library
```

Back up artwork cache:

```powershell
Compress-Archive -Path backend\storage\app\artwork -DestinationPath artwork-backup.zip
```

The music files themselves are not copied, moved, deleted, or backed up by this
application. They remain on the configured library drives.

## LAN Access

The current development setup binds the Laravel and Vite servers to
`127.0.0.1`. That is intentional.

Settings-like API operations are protected by Laravel middleware:

- `GET /api/folders*`
- all `/api/library_roots*` operations
- all `/api/scan_runs*` operations

These endpoints are always allowed from `127.0.0.1` and `::1`. From another LAN
address they are rejected unless LAN mode is enabled and the request includes
the configured admin token:

```text
MUSIC_LIBRARY_LAN_ENABLED=true
MUSIC_LIBRARY_ADMIN_TOKEN=choose-a-long-random-token
```

Remote admin requests must include:

```text
X-Music-Library-Admin-Token: choose-a-long-random-token
```

Catalog browsing, artwork, audio streaming, favorites, and playlists are not
blocked by this middleware.

The frontend does not yet have a token entry field, so remote LAN browsing can
work once the services are bound to the LAN interface, but remote Settings UI
operations still need a small frontend follow-up to send the admin token.

Before exposing the app on the local network, the project also still needs
stricter CORS and trusted-host configuration.

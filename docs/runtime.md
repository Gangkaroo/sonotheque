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

The queue worker also delivers enabled Last.fm scrobbles. Last.fm playback never
blocks local audio playback; temporary delivery failures are retried in the
background.

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

From the repository root, start the complete local stack with:

```powershell
.\scripts\start.ps1
```

## Last.fm Connection

Create API credentials at `https://www.last.fm/api/account/create`, then open
Settings > Connections. Enter the API key and shared secret, open the Last.fm
authorization page, approve access, and complete the connection in the app.

The shared secret and Last.fm session key are encrypted with Laravel's
`APP_KEY`. They are not returned by the settings API. Keep `backend/.env` and its
`APP_KEY` stable; replacing the key makes existing encrypted connector state
unreadable and requires reconnecting the account.

Outbound Last.fm requests are direct by default. Networks that require a proxy
can set `LASTFM_PROXY`. If PHP has no `curl.cainfo` or `openssl.cafile`, set
`LASTFM_CA_BUNDLE` to a maintained PEM bundle, for example Git for Windows'
`mingw64/etc/ssl/certs/ca-bundle.crt`. TLS verification must remain enabled.

Eligible tracks are longer than 30 seconds and have played for at least half
their duration or four minutes, whichever comes first. The same rule controls
the local play count and Last.fm submission.

To opt into LAN access, first configure a long admin token in `backend/.env`,
stop any currently running local instance, and start LAN mode:

```text
MUSIC_LIBRARY_ADMIN_TOKEN=replace-with-at-least-32-random-characters
```

```powershell
.\scripts\stop.ps1 -KeepDatabase
.\scripts\start.ps1 -Lan
```

The script detects the active private IPv4 address. If the computer has several
eligible adapters, or a particular address should be used, select it explicitly:

```powershell
.\scripts\start.ps1 -Lan -LanAddress 192.168.1.10
```

Local and LAN modes cannot be switched while the managed web processes are
running. This prevents an apparently successful restart from leaving the old
bind address or security configuration active.

This is always a manual action. The scripts do not register a Windows startup
task, service, or scheduled job.

Check the current state at any time:

```powershell
.\scripts\status.ps1
```

Stop the local stack:

```powershell
.\scripts\stop.ps1
```

Keep PostgreSQL running while stopping the managed PHP and Node processes:

```powershell
.\scripts\stop.ps1 -KeepDatabase
```

The scripts start native processes in hidden windows and record their process
identity under `runtime-logs/`. Shutdown only terminates native processes whose
recorded identity still matches. Services that were started manually are shown
as `external` and are left untouched. The named PostgreSQL Compose service is
stopped by default unless `-KeepDatabase` is used.

If the local PowerShell execution policy blocks repository scripts, invoke them
without changing the system-wide policy:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start.ps1
```

For manual diagnostics, the equivalent commands remain:

```powershell
docker compose up -d postgres
cd backend
& $php85 artisan serve --host=127.0.0.1 --port=8000
& $php85 artisan queue:listen --tries=1 --timeout=0 --memory=512 --sleep=1
cd ..\frontend
npm run dev -- --host 127.0.0.1 --port 5173
```

`queue:listen` supervises a fresh worker process for each scan. This prevents a
large completed scan from leaving the application without a queue listener when
Laravel retires a child worker after crossing its memory threshold.

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

Cover paths are configured as an ordered list per library root and resolved
relative to each album folder. The first existing candidate is preferred, so a
root can check values such as `cover.jpg`, `artwork/front.jpg`, and
`Disc 1/front.jpg`. Parent-relative candidates such as `../Cover/Front.jpg` are
also supported for multi-disc layouts. They are resolved separately for each
album and rejected if the result would leave the library root. Embedded artwork
is used as a fallback when no configured candidate can be used.

Settings can also hold explicit excluded folders relative to each library root.
An excluded folder and all descendants are pruned during the discovery pass, so
their files are neither counted nor parsed. After a successful, trustworthy
scan, files that were deleted, moved, or newly excluded are removed from the
catalog together with empty albums and unreferenced artists or genres. Records
beneath an unreadable path are preserved, while unrelated stale or explicitly
excluded paths can still be cleaned. A completely unavailable root preserves
the existing catalog.

## Playback Statistics Synchronization

Listening-statistics synchronization is disabled by default in Settings. When
enabled, scans import `PLAY_COUNT`, `FIRST_PLAYED_TIMESTAMP`, and
`LAST_PLAYED_TIMESTAMP`. A newly counted app play queues a coalesced write-back
job after a short delay.

Export currently supports MP3 files with ordinary ID3v2.3 or ID3v2.4 tags.
Unrelated ID3 frames and audio bytes are preserved and the written values are
verified before replacing the original file. Unsupported formats and unusual
ID3 layouts remain database-only; playback itself is never blocked by export.
The queue listener must be running for write-back jobs to execute.

## Metadata Editing

Track detail pages can edit title, track artists, composers, performers,
comment, track number, disc number, and year for MP3 files with ordinary
ID3v2.3 or ID3v2.4 tags. The UI first requests a fingerprinted preview and
displays every changed value. Confirmation creates a queued edit;
the worker writes a temporary file, verifies the resulting tags, replaces the
original with a short-lived rollback copy, and refreshes the database fingerprint
and raw metadata.

Unrelated ID3 frames, separately described comment frames, embedded artwork,
playback-statistics fields, track/disc totals, and audio bytes are preserved.
Unusual ID3 layouts and non-MP3 formats are rejected rather than rewritten.
Metadata edits require the queue listener and are protected by the same
local/LAN administrative middleware as Settings.

When an existing ID3v2 tag has enough padding for the updated frames, only its
fixed-size tag block is rewritten. The editor stores and flushes recovery bytes,
verifies the result, and restores the original tag if verification fails. If a
tag must grow or does not yet exist, the editor retains the full-file temporary
copy and replacement path.

Album detail pages can edit album title, album artist, release year, total
discs, and shared genres across all tracks in an MP3-only album. The preview
lists every file and blocks mixed-format batches before writing. The worker
processes files sequentially and reports per-file progress and failures. Track
titles, track numbers, and existing disc numbers remain unchanged; a file with
no disc number receives disc 1 when a total is added. The shared album catalog
record and genre relationships update only after every file succeeds.

### Metadata Backups

Durable metadata backups are disabled by default. They can be enabled in
Settings with a writable folder outside every configured music library root and
a retention period from 1 to 3650 days. When enabled, a checksum-verified copy
must be recorded before a queued track or album metadata edit can write its
source file. A backup failure prevents that file from being edited.

Backups retain the library root and source-relative path, use unique job-based
directories without overwriting an earlier copy, and expose their record ID and
path in metadata-edit failure details. Expired backup files are removed when a
new backup is created or explicitly with:

```powershell
php artisan music:metadata-backups:cleanup
```

Audit records remain in PostgreSQL after retention cleanup. Restore a retained
backup by its record ID:

```powershell
php artisan music:metadata-backups:restore 123
```

The restore command verifies path containment and the stored SHA-256 checksum,
asks before replacing the current file, and uses a temporary rollback copy.
Use `--force` only for non-interactive operation. Run a library rescan after a
restore so normalized catalog metadata matches the restored tags.

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

The `*.process.json` files in the same directory are ownership records used by
the shutdown script. They are runtime state, not configuration, and should not
be edited manually.

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
Invoke-WebRequest -Uri http://127.0.0.1:8000/up -UseBasicParsing
```

If scans stay queued, run the status command and check that the queue listener
is running. Then inspect failed jobs:

```powershell
.\scripts\status.ps1
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

Back up generated artwork thumbnails:

```powershell
Compress-Archive -Path backend\storage\app\artwork -DestinationPath artwork-backup.zip
```

The music files themselves are not copied, moved, deleted, or backed up by this
application. They remain on the configured library drives.

Folder cover originals are served from their source location and are no longer
copied into application storage. Embedded originals are extracted from their
audio files only when requested. Thumbnails remain cached for responsive list
views.

After applying the artwork-source migration, remove the obsolete full-size
artwork cache:

```powershell
php artisan music:artwork:remove-original-cache
```

## LAN Access

Local startup remains bound to `127.0.0.1`. LAN startup is always an explicit
manual action using `scripts/start.ps1 -Lan`; nothing is registered to start
with Windows.

LAN mode binds Vite to one selected private IPv4 address while Laravel remains
on loopback. Browsers therefore need only TCP port `5173`; Vite proxies `/api`
to Laravel and supplies the original client address. Laravel trusts forwarded
client addresses only from its loopback proxy, so remote clients still pass
through the LAN admin-token boundary and cannot make themselves appear local.

Settings-like API operations are protected by Laravel middleware:

- `GET /api/folders*`
- all `/api/library_roots*` operations
- all `/api/scan_runs*` operations

These endpoints are always allowed from a direct `127.0.0.1` or `::1` request.
From another LAN address they are rejected unless LAN mode is enabled and the
request includes the configured admin token. `start.ps1 -Lan` enables LAN mode
for its managed backend process and automatically trusts the selected address
and computer hostname. Only the token needs to be stored in `backend/.env`:

```text
MUSIC_LIBRARY_ADMIN_TOKEN=choose-a-long-random-token
```

Generate a suitable token with PHP if needed:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Remote admin requests must include:

```text
X-Music-Library-Admin-Token: choose-a-long-random-token
```

Catalog browsing, artwork, audio streaming, favorites, and playlists are not
blocked by this middleware.

The Security tab in Settings verifies the token before saving it. By default it
is kept in `sessionStorage` until the tab or browser session ends. The optional
"Remember on this device" setting uses `localStorage`. The token is sent only
to same-origin `/api` requests and is never returned by the backend. Clearing
it also clears protected library-root and scan data from the current page.

`MUSIC_LIBRARY_TRUSTED_HOSTS` is a comma-separated list of exact hostnames and
IP addresses accepted in HTTP `Host` headers. LAN startup combines any entries
already configured there with localhost, the selected address, and the computer
hostname. Requests with any other host are rejected.

Cross-origin API access is denied by default. The Vue development proxy and a
normal same-origin deployment do not need CORS. If the frontend is deliberately
served from a different origin, list only those exact origins:

```text
MUSIC_LIBRARY_ALLOWED_ORIGINS=http://192.168.1.10:5173
```

### Windows Firewall

If another device cannot open the URL printed by `start.ps1 -Lan`, first make
sure the active Windows network profile is `Private`. Then run this once from
an elevated PowerShell window, replacing the address with the one printed by
the startup script:

```powershell
New-NetFirewallRule -DisplayName 'Music Library LAN' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 5173 -LocalAddress 192.168.1.10 -RemoteAddress LocalSubnet -Profile Private
```

The rule deliberately permits only the Vite port, only on Private networks,
only on the selected local address, and only from the local subnet. Do not add
a public-profile rule or expose PostgreSQL port `5433` or Laravel port `8000`.

Verify from a second device by opening the LAN URL shown by the startup script.
Catalog browsing should work without a token. Settings operations should remain
blocked until the same token is entered and verified in the Security tab.

Use `scripts/status.ps1` to see the recorded runtime mode and active app URL.
If DHCP assigns a different address later, stop the app and start LAN mode again.

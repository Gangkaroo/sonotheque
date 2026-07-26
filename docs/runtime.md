# Runtime Guide

This guide describes how to run the local music library during development on
Windows. The app is designed to run locally first: PostgreSQL runs in Docker,
while PHP/Laravel runs natively so the scanner can access folders on local and
external drives.

For the planned non-developer setup flow, packaged Docker runtime, first-run
setup, and backup story, see `docs/setup-and-distribution.md`.

## Services

The development stack has five moving parts:

- PostgreSQL 18 in Docker, exposed on `127.0.0.1:5433`.
- Laravel API on `http://127.0.0.1:8000`.
- Laravel database queue worker for scans.
- Laravel scheduler for maintenance tasks and health heartbeats.
- Vue/Vite frontend on `http://127.0.0.1:5173`.

The frontend proxies `/api` requests to `http://127.0.0.1:8000`.

The queue worker also delivers enabled Last.fm scrobbles. Last.fm playback never
blocks local audio playback; temporary delivery failures are retried in the
background.

## Requirements

- Docker Desktop.
- PHP 8.5 with the PostgreSQL and GD extensions.
- FFmpeg/FFprobe for recovering metadata and stream details when getID3 cannot
  parse an otherwise playable file.
- Composer.
- Node.js 22.12 or newer.
- npm 10 or newer.

On this machine, an older XAMPP PHP can appear first on `PATH`. Use the PHP 8.5
binary explicitly when needed:

```powershell
$php85 = "C:\Users\Tom\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$composerPhar = "C:\ProgramData\ComposerSetup\bin\composer.phar"
& $php85 --version
```

Do not use bare `php` or `composer` commands unless `php --version` first
confirms PHP 8.5. The installed `composer.bat` currently resolves PHP 8.2.
Invoke Composer through PHP 8.5 instead:

```powershell
& $php85 $composerPhar install
& $php85 artisan migrate --force
& $php85 artisan test
& $php85 vendor\bin\pint --test
```

The `composer check:autoload` script recursively invokes the PATH-bound
Composer on this machine and can hang after it has generated the autoloader.
Use its strict underlying command directly:

```powershell
& $php85 $composerPhar dump-autoload --optimize --strict-psr --no-scripts
```

The managed startup scripts already locate PHP 8.5 themselves. Set
`SONOTHEQUE_PHP` only when that executable moves to another location.

Confirm that the metadata fallback is available in development mode:

```powershell
ffprobe -version
```

The packaged image installs FFmpeg automatically. `FFPROBE_BINARY` and
`FFPROBE_TIMEOUT_SECONDS` can override the local metadata fallback.
`FFMPEG_BINARY` and `AUDIO_FINGERPRINT_TIMEOUT_SECONDS` configure the fallback
used when getID3 cannot expose a safe audio-only byte range. Both external tools
remain fallbacks, so normal scans retain their in-process fast path.

## Optional Audio Intelligence

Audio intelligence is disabled by default. The standard Sonotheque runtime does
not install Python packages, download a model, start an analyzer, or inspect
audio for this feature. Enabling the workspace only permits preparation; it
does not dispatch analysis or reserve CPU, GPU, or memory.

The representative validation sample accepts 50 through 500 tracks and
defaults to 200. Sonotheque selects available tracks across enabled roots,
genres, and artists. Preparation reuses current tag-independent fingerprints,
keeps a small reserve for missing or changed files, and does not generate
features or embeddings. Existing validation runs remain available for
comparison. Files whose catalog size or modification time no longer matches are
skipped until the library is rescanned.

The local analyzer targets Essentia and the Discogs
multi-similarity EffNet embedding model. Essentia's Python bindings are not
available for native Windows Python, so the development setup uses an isolated
Linux container. Build it explicitly:

```powershell
docker build --tag sonotheque-audio-intelligence:analysis .\audio-intelligence
```

Download and review the model separately, keep it beneath the ignored
`audio-intelligence/models` directory, then configure the backend environment:

```dotenv
AUDIO_INTELLIGENCE_DRIVER=essentia_docker
AUDIO_INTELLIGENCE_MODEL_PATH=C:/absolute/path/to/discogs-effnet.pb
AUDIO_INTELLIGENCE_DOCKER_IMAGE=sonotheque-audio-intelligence:analysis
AUDIO_INTELLIGENCE_BENCHMARK_CPU_IMAGE=sonotheque-audio-intelligence:analysis
AUDIO_INTELLIGENCE_BENCHMARK_CUDA_IMAGE=sonotheque-audio-intelligence:cuda
AUDIO_INTELLIGENCE_BENCHMARK_SAMPLE_SIZE=15
AUDIO_INTELLIGENCE_ACCELERATOR=cpu
AUDIO_INTELLIGENCE_PERSISTENT=false
AUDIO_INTELLIGENCE_CPU_LIMIT=2
AUDIO_INTELLIGENCE_MEMORY_LIMIT=4g
AUDIO_INTELLIGENCE_PREPARATION_WORKERS=2
```

The two-CPU default is conservative. On a machine with at least eight logical
processors, a four-CPU limit is a reasonable first measured adjustment. CPU and
memory limits do not affect artifact identity, so changing them never causes
completed tracks to be analyzed again.

On the development machine, bounded decoding plus a four-CPU limit reduced one
6:16 MP3 from 29.3 seconds to 19.6 seconds (about 33%). The optimized run spent
7.6 seconds decoding, 2.3 seconds extracting conventional features, and 9.2
seconds generating the embedding. These per-stage timings are stored for newly
created artifacts; existing reusable artifacts remain valid and are not
recomputed merely to populate timing data.

Restart the backend and queue worker after changing these values. In Settings,
enable the Audio intelligence workspace, prepare the bounded validation sample,
use **Check analyzer**, and explicitly start the validation run. Preparation
selects catalog candidates first and calculates fingerprints only for that
sample plus a small reserve. The analysis worker analyzes at most three
representative 30-second windows per long track. Each window is
decoded directly rather than loading the entire track. The worker stores the
exact analyzer/model versions, model checksum, licenses, features,
embedding, total runtime, decode/feature/embedding timings, hardware
description, and per-file result.

Laravel submits five tracks per analyzer container by default. Each completed
chunk persists results and updates the Settings progress display. Cancellation
is durable and takes effect between chunks, so completed results are retained
while unstarted items are marked cancelled. Override
`AUDIO_INTELLIGENCE_CHUNK_SIZE` only after measuring the tradeoff between model
startup overhead and cancellation responsiveness.

The remaining-time estimate uses actual wall-clock analyzer throughput from the
latest 20 chunks. It deliberately does not sum per-track decode, feature, and
embedding timings because CUDA preparation overlaps those stages. The rolling
sample resets when the analyzer image, accelerator, resource limits, worker
count, persistence mode, or chunk size changes.

After validating the bounded pool, Settings can prepare collection analysis for
all enabled library roots or one selected root. The work list is enumerated in
database-sized chunks and stores its last catalog-track checkpoint. Preparing
or analyzing a later overlapping scope reuses current fingerprints and matching
artifacts rather than decoding the same audio again.

Pause is distinct from cancellation. A pause request takes effect between
fingerprint items or analyzer chunks, leaves unfinished items queued, and can be
resumed immediately on the same run. Completed and reused items remain final.
Cancelling releases the scope so another run can be prepared; a cancelled run
can still be resumed deliberately.

Analysis output is stored as a reusable artifact identified by the exact
analyzer/model profile, audio-content fingerprint, and fingerprint version.
Starting or resuming an analysis run first links every matching artifact and
sends only the remaining audio to the analyzer. Tag-only edits, file renames,
and moves retain the audio fingerprint and therefore reuse the existing work.
Changed audio, a changed fingerprint algorithm, or a different model profile
creates new work instead of silently reusing an incompatible result.

Paused, failed, partial, and cancelled preparation or analysis runs can be
resumed from Settings. Preparation reuses current fingerprints and continues
after the last persisted enumeration checkpoint or with the first unfinished
fingerprint. A fingerprinting, queued, or running run becomes
resumable only after its heartbeat is older than
`AUDIO_INTELLIGENCE_RESUME_STALE_MINUTES` (10 minutes by default), which avoids
starting the same job twice while a worker is healthy. Completed and reused
items are never submitted again. If a worker is forcibly stopped while an
analyzer chunk is active, that one uncommitted chunk may run again; reducing the
chunk size narrows that boundary but increases model startup overhead.

After a validation run has results, Settings exposes a similarity evaluator.
Choose an analyzed source track to calculate and display its ten nearest tracks
using exact cosine similarity. The response includes measured calculation time,
scores, BPM/key context, and catalog links, but never returns embeddings or
filesystem paths and never modifies playback, queues, or playlists.

The evaluator also summarizes BPM, danceability, dynamic complexity, and the
analyzer's raw loudness values with compact eight-bin distributions. Reviewers
can exclude candidates from the same artist or album and mark individual
matches relevant or not relevant. Feedback is stored against the exact
analyzer/model profile and track pair, so replacing a model does not silently
mix ratings from incompatible embeddings. Ratings are also scoped to the active
same-artist and same-album exclusion configuration, so comparisons do not mix
different candidate sets.

The structured review queue deterministically chooses up to 30 source tracks
while balancing library roots, genres, artists, and albums. It resumes a
partially rated source before offering a new one and tracks completed sources,
the number of rated matches, the overall rated-relevant share, and the mean
relevant share for completed sources. Changing exclusion switches shows the
separate progress and metrics for that configuration. These controls assess the
unweighted embedding baseline; Sonotheque does not apply BPM or key heuristics
yet.

The adapter uses `--pull=never`, disables container networking during health and
analysis runs, applies the configured CPU and memory limits, and mounts the
model plus sampled audio files read-only. It never downloads or modifies the
configured model. The model path should point to a manually reviewed, read-only
file. Do not enable this experimental adapter before reviewing the analyzer and
model licenses for the intended use.

The CPU image does not include CUDA runtime libraries. A separate, optional
image uses a TensorFlow runtime compatible with Pascal-generation NVIDIA cards
and keeps Essentia's preprocessing and the configured EffNet graph unchanged:

```powershell
docker build --file .\audio-intelligence\Dockerfile.cuda `
  --tag sonotheque-audio-intelligence:cuda .\audio-intelligence
```

Docker Desktop must expose a compatible NVIDIA GPU to Linux containers. Enable
the image explicitly and restart the backend plus queue worker:

```dotenv
AUDIO_INTELLIGENCE_DOCKER_IMAGE=sonotheque-audio-intelligence:cuda
AUDIO_INTELLIGENCE_ACCELERATOR=cuda
AUDIO_INTELLIGENCE_PERSISTENT=true
```

CPU remains the default. The CUDA adapter refuses to report healthy when no GPU
is visible, mounts the same files read-only, and does not change analyzer
profile or artifact identity. Existing completed artifacts are therefore
reused. On the development GTX 1070, an identical five-track chunk took 57.3
seconds through CUDA and 70.1 seconds through the four-CPU image, an 18%
end-to-end improvement. The GPU image is several gigabytes and TensorFlow
startup remains significant, so the benefit depends on the GPU and chunk size.
Return both settings to the CPU values above if health checks or measured
throughput are worse on another machine.

The CUDA worker batches model patches from every representative window in the
current request into shared 64-patch TensorFlow calls. It also uses a bounded
preparation pipeline so two CPU workers can decode audio and extract Essentia
features while TensorFlow handles the preceding prepared group. Configure this
with `AUDIO_INTELLIGENCE_PREPARATION_WORKERS`; values are limited to `1..4`,
and `2` is the measured default.

The pipeline is enabled only when the CUDA TensorFlow model is active. CPU
analysis remains on its existing sequential path, never requests Docker GPU
devices, and works without CUDA or an NVIDIA runtime. A machine without CUDA
should retain `AUDIO_INTELLIGENCE_ACCELERATOR=cpu` and use the CPU image.

Before the preparation pipeline, two identical five-track comparisons on the
GTX 1070 reduced wall time from 76.0 to 59.8 seconds and from 63.2 to 54.2
seconds through cross-window model batching. With the preparation pipeline,
the controlled five-track comparison reduced wall time from 106.2 to 52.4
seconds. All five normalized embeddings were byte-for-byte identical and all
extracted features matched.

The first sustained collection session after enabling the preparation pipeline
processed 250 tracks at 7.77 tracks per minute. Comparable pre-pipeline sessions
processed approximately 4.1 to 4.6 tracks per minute. Per-track stage totals
were higher under contention, but those stages overlap and therefore are not a
valid end-to-end throughput measurement.

Advanced diagnostics provides an adaptive analyzer benchmark. It uses the same
15 readable tracks for every configuration, records no analysis artifacts, and
does not alter collection-run progress. The first stage compares CPU with CUDA
preparation workers `1`, `2`, and `3` at chunk size `5`. It then benchmarks
chunk sizes `10` and `15` with the first-stage winner, for six configurations
in total. Results include wall time, tracks per minute, average stage timings,
and CPU-reference output verification. Missing CUDA support is reported as
unavailable while the CPU configurations continue. The benchmark may run while
a collection analysis is paused, can be cancelled between chunks, and prevents
analysis from resuming concurrently.

Persistent mode starts a named analyzer container on the first analysis request
and keeps the selected model loaded between chunks. The container has no Docker
network, accepts framed JSON only through an internal Unix socket invoked with
`docker exec`, and mounts the model plus encountered configured library roots
read-only. A changed image, accelerator, model file, resource limit, or newly
required root recreates the service deliberately. A failed persistent request
is retried once with a fresh service and then falls back to the same
accelerator's one-shot container; it never changes CPU/CUDA method silently.

On the development GTX 1070, the first Laravel request including persistent
service startup took 22.9 seconds. A separate request through the warmed service
took 8.7 seconds, including about 0.7 seconds of Laravel/Docker communication
around 8.0 seconds of recorded analysis. `scripts/stop.ps1` removes Sonotheque
containers carrying the audio-analyzer label so stopping the local application
also releases model memory and GPU resources. The analysis job itself removes
its persistent analyzer when the run completes, fails, is paused, or is
cancelled; the stop script is an additional cleanup safeguard.

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

## Discogs Connection

Create a personal access token in the Discogs developer settings, then open
Settings > Connections and connect the account. Sonotheque validates the token
against the Discogs identity endpoint before storing it. The token is encrypted
with the application's `APP_KEY` and is never returned by the settings API.

`DISCOGS_PROXY` and `DISCOGS_CA_BUNDLE` provide the same explicit proxy and TLS
certificate overrides as the other external providers. Keep TLS verification
enabled. Album-to-release matching is read-only initially; changing the Discogs
collection remains a later, separately confirmed feature.

To link an owned copy, first save it under an album's Personal information.
An album may contain several independently editable physical or digital copies,
each with its own format, purchase details, condition, notes, and provider
association. The album then shows **Match Discogs release** below each copy's
ownership details. The
matcher starts with the local artist, album title, release year, and physical
format; country, catalog number, and barcode can narrow the search. Review the
edition details and open its Discogs page when needed, then explicitly choose
**Link**. Sonotheque records the exact release ID and, when Discogs reports one
unambiguous collection copy, its collection instance and folder IDs. Changing
or unlinking this association never removes the copy from the Discogs
collection and does not alter the local music files.

If the exact release occurs more than once in the connected collection, the
matcher asks which instance to use and shows its collection folder, date added,
and instance ID. Linked copies load cached edition details asynchronously so an
unavailable Discogs service never delays the album itself. The refresh action
rechecks the release, collection membership, and folder association; it asks
again if the previously linked instance became ambiguous.

Release thumbnails are not loaded directly by the browser. Sonotheque accepts
only registered HTTPS images from the Discogs image host, validates their media
type, size, and dimensions, and serves subsequent requests from private local
storage.

## Online Information And Lyrics

Settings > Connections contains separate opt-in switches for artist/album
information and lyrics. Artist and album context uses the configured Last.fm
API key; authorizing a Last.fm user session is not required for these read-only
requests. MusicBrainz adds structured artist and release identity without
credentials. It prefers MusicBrainz identifiers already retained from file
tags and falls back to strict exact-name matching; ambiguous results are left
unattached. Lyrics use LRCLIB and require no API credentials. Timestamped lyrics
follow the local playback position and each displayed line can be used to seek;
plain lyrics remain available when synchronized content is not returned.

Provider requests start only when the corresponding Info or Lyrics tab is
opened. Successful results are cached for 30 days, remain available as stale
content for another 7 days while a unique background refresh runs, and missing
results are cached for 24 hours. Repeated failures use exponential backoff.
Atomic cache locks deduplicate concurrent misses, while configurable
provider-specific request limits prevent bursts. MusicBrainz requests are also
paced to at least 1.1 seconds apart by default. Playback, seeking, and queue
progression never wait for background refreshes.

Settings > Connections provides explicit Last.fm, MusicBrainz, and LRCLIB connection checks,
cache statistics, and a confirmation-protected action that clears only online
enrichment entries. Connection checks run only when clicked and use fixed test
values rather than the currently playing track. Cache durations, lock timing,
request limits, and LRCLIB connection settings can be adjusted with the
`ENRICHMENT_*`, `LASTFM_ENRICHMENT_*`, `MUSICBRAINZ_*`, and `LRCLIB_*` values documented in
`backend/.env.example`.

Set `ENRICHMENT_CA_BUNDLE` when PHP needs an explicit certificate authority
bundle for outbound HTTPS. `LASTFM_CA_BUNDLE`, `MUSICBRAINZ_CA_BUNDLE`, and
`LRCLIB_CA_BUNDLE` can override it per provider; LRCLIB also reuses an existing
Last.fm bundle for compatibility.

To opt into LAN access, first configure a long admin token in `backend/.env`,
stop any currently running local instance, and start LAN mode:

```text
SONOTHEQUE_ADMIN_TOKEN=replace-with-at-least-32-random-characters
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
& $php85 artisan schedule:work
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

Supported audio extensions are configured in `backend/config/sonotheque.php`.
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

The Folders view can rename a visible audio file or folder within its current
parent. Sonotheque performs the filesystem rename and then updates all affected
media-file and album paths while retaining the existing track IDs. Playlist
items, favorites, listening statistics, and queued track references therefore
remain attached. Renames reject overwrites, symbolic links, unsafe or reserved
names, audio-extension changes, read-only locations, and roots with an active
scan. On a catalog-update failure, Sonotheque attempts to roll the filesystem
rename back immediately.

A file moved or renamed outside Sonotheque requires a rescan. During a full-root
scan, Sonotheque compares newly discovered paths with missing old paths by a
stored SHA-256 fingerprint of the audio payload. getID3 byte boundaries exclude
leading and trailing tags where available; FFmpeg's stream-copy hash is the
fallback for containers without a safe byte range. ID3 and APEv2 edits therefore
do not alter an MP3 fingerprint.

An old path is reused only when exactly one missing record and one new path have
the fingerprint. The existing media-file and track IDs then survive, preserving
playlist membership, favorites, statistics, and other references. Ambiguous
duplicate copies are handled conservatively as removed and added files rather
than risking the wrong association. A reconciled file is reparsed even when its
size and modification time were preserved, so simultaneous tag edits are still
imported. A subtree scan can only reconcile a move
when both its old and new paths are inside that subtree; use a full-root scan for
moves across subtree boundaries.

The first successful scan after installing the fingerprint migration builds the
baseline for existing files. A file moved before its old record has a baseline
fingerprint cannot be reconciled retroactively.

## Playback Statistics Synchronization

Listening-statistics synchronization is disabled by default in Settings. When
enabled, scans import `PLAY_COUNT`, `FIRST_PLAYED_TIMESTAMP`, and
`LAST_PLAYED_TIMESTAMP`. A newly counted app play queues a coalesced write-back
job after a short delay.

Export currently supports MP3 files with ordinary ID3v2.3 or ID3v2.4 tags and
converts safely mappable ID3v2.2 tags to ID3v2.3 before writing.
Unrelated ID3 frames and audio bytes are preserved and the written values are
verified before replacing the original file. Unsupported formats and unusual
ID3 layouts remain database-only; playback itself is never blocked by export.
The queue listener must be running for write-back jobs to execute.

## Metadata Editing

Track detail pages can edit title, track artists, composers, performers,
comment, track number, disc number, and year for MP3 files with ordinary
ID3v2.3 or ID3v2.4 tags. ID3v2.2 tags are converted to ID3v2.3 when every
legacy frame has a lossless mapping; unknown frames and compressed or
unsynchronized v2.2 tags are rejected. The UI first requests a fingerprinted preview and
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
& $php85 vendor\bin\pint --test
& $php85 artisan test
& $php85 $composerPhar dump-autoload --optimize --strict-psr --no-scripts
```

Frontend checks:

```powershell
cd frontend
npm run lint
npm run type-check
npm run test
npm run build
```

The backend test suite uses PostgreSQL database `sonotheque_test` on port
`5433`. Create it once if it does not exist:

```powershell
docker exec -it sonotheque-postgres createdb -U sonotheque sonotheque_test
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
DB_DATABASE=sonotheque
DB_USERNAME=sonotheque
DB_PASSWORD=sonotheque
```

If the wrong PHP version is used, `php --version` may show the XAMPP binary.
Use `$php85` explicitly for Artisan commands.

If album covers do not update after changing files on disk, rescan the affected
library root. Cached artwork is stored under:

```text
backend/storage/app/artwork
```

## Backup And Recovery

Create a complete development-mode backup from the repository folder:

```powershell
.\scripts\backup.ps1 -Mode Development
```

For packaged mode, use:

```powershell
.\scripts\backup.ps1 -Mode Packaged
```

Bundles are written below `backups/` by default. Each checksummed bundle contains:

- `database.dump`: catalog, settings, favorites, playlists, listening history,
  personal album data, and scan history.
- `storage.tar`: application storage, including artwork thumbnails and
  cached online content stored as files.
- `app-key.txt`: the Laravel key required to decrypt protected settings.
- `manifest.json`: runtime mode, creation time, file sizes, and SHA-256 hashes.

The bundle contains a secret key and must be stored securely. Music files and
folder cover originals are not included. A metadata-edit backup directory
configured outside `backend/storage/app` must be backed up separately.

Restore a development bundle only after checking its path:

```powershell
.\scripts\restore.ps1 `
  -BackupPath ".\backups\sonotheque-development-20260712-120000" `
  -Mode Development `
  -Force
```

Use `-Mode Packaged` for a packaged bundle. Restore rejects altered files and
bundles created for the other runtime mode. It creates a safety backup under
`backups/pre-restore/`, stops application writers, restores PostgreSQL and
storage, runs migrations, and restarts the previous runtime by default.

If the bundle comes from another installation with a different `APP_KEY`, add
`-UseBackupAppKey`. Use `-SkipSafetyBackup` only when a separate current backup
has already been verified. `-NoRestart` leaves application services stopped.

Folder cover originals are served from their source location and are no longer
copied into application storage. Embedded originals are extracted from their
audio files only when requested. Thumbnails remain cached for responsive list
views.

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
SONOTHEQUE_ADMIN_TOKEN=choose-a-long-random-token
```

Generate a suitable token with PHP if needed:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Remote admin requests must include:

```text
X-Sonotheque-Admin-Token: choose-a-long-random-token
```

Catalog browsing, artwork, audio streaming, favorites, and playlists are not
blocked by this middleware.

The Security tab in Settings verifies the token before saving it. By default it
is kept in `sessionStorage` until the tab or browser session ends. The optional
"Remember on this device" setting uses `localStorage`. The token is sent only
to same-origin `/api` requests and is never returned by the backend. Clearing
it also clears protected library-root and scan data from the current page.

The Security tab does not set or rotate the server token. If browser storage is
cleared, enter the existing `SONOTHEQUE_ADMIN_TOKEN` again. To replace that
server token in development mode, stop Sonotheque, update `backend/.env`, and
restart with `scripts/start.ps1 -Lan`. For the portable runtime, stop the app,
update `SONOTHEQUE_ADMIN_TOKEN` in `.env.packaged`, and restart with
`scripts/start-packaged.ps1 -Lan`. Laravel reads the value when the backend
process starts, so editing either file while the app is running does not change
the active token.

`SONOTHEQUE_TRUSTED_HOSTS` is a comma-separated list of exact hostnames and
IP addresses accepted in HTTP `Host` headers. LAN startup combines any entries
already configured there with localhost, the selected address, and the computer
hostname. Requests with any other host are rejected.

Cross-origin API access is denied by default. The Vue development proxy and a
normal same-origin deployment do not need CORS. If the frontend is deliberately
served from a different origin, list only those exact origins:

```text
SONOTHEQUE_ALLOWED_ORIGINS=http://192.168.1.10:5173
```

### Windows Firewall

If another device cannot open the URL printed by `start.ps1 -Lan`, first make
sure the active Windows network profile is `Private`. Then run this once from
an elevated PowerShell window, replacing the address with the one printed by
the startup script:

```powershell
New-NetFirewallRule -DisplayName 'Sonotheque LAN' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 5173 -LocalAddress 192.168.1.10 -RemoteAddress LocalSubnet -Profile Private
```

The rule deliberately permits only the Vite port, only on Private networks,
only on the selected local address, and only from the local subnet. Do not add
a public-profile rule or expose PostgreSQL port `5433` or Laravel port `8000`.

Run the host-side LAN preflight after startup:

```powershell
.\scripts\verify-lan.ps1
```

It verifies the recorded LAN address, exact frontend listener, public catalog
access, anonymous and invalid-token rejection, and valid-token acceptance. It
also inspects the scoped firewall rule when run from an elevated PowerShell
window. The token is read from `backend/.env` for the request but is never
printed.

Verify from a second device by opening the LAN URL shown by the startup script.
Browse albums, open artwork, and play and seek a track. Catalog and playback
should work without a token, while protected Settings tabs remain disabled.
Enter the same token in Settings > Security, verify that the protected tabs can
load, then clear it and confirm they lock again.

The development LAN workflow was physically verified on July 14, 2026 from a
second device: catalog browsing and playback succeeded over the private network,
and Settings and modification operations remained unavailable without the
admin token. The host preflight also confirmed anonymous and invalid-token
rejection plus valid-token acceptance.

Use `scripts/status.ps1` to see the recorded runtime mode and active app URL.
If DHCP assigns a different address later, stop the app and start LAN mode again.

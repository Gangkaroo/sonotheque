# Setup And Distribution Plan

This document describes the target setup experience for people who want to use
Sonotheque without working directly with Laravel, Vite, Composer, or
the queue worker.

The current `docs/runtime.md` remains the developer runtime guide. The plan
below defines the more user-friendly packaging path that should sit beside it.

## Target User Experience

The preferred setup should feel like a small local appliance on Windows,
Linux, and macOS:

1. Install Docker Desktop or a compatible Docker Engine with Compose.
2. Download and extract the portable release archive.
3. Start Sonotheque with the platform launcher and select or enter the music
   folders.
4. Open one URL.
5. Complete a first-run setup screen.
6. Start the first scan.

Users should not need to know that the app contains Laravel, Vue, PostgreSQL, a
queue worker, or separate development ports.

Windows, Linux, and macOS share the same configuration generator. The POSIX
launcher covers first start, lifecycle, browser launch, LAN mode, root
configuration, checksummed backup/restore, and optional Audio Intelligence
provisioning. Host-native folder/model pickers are used where available, with a
terminal fallback. Broader real-hardware validation remains ongoing.

## Runtime Modes

### Development Mode

Development mode is the current setup:

- PostgreSQL runs in Docker.
- Laravel runs natively on Windows.
- Separate interactive, scan, and audio-analysis queue listeners run natively
  on Windows.
- Vite serves the frontend on port `5173`.
- The scripts under `scripts/` start and stop those processes manually.

This mode remains useful while the scanner and metadata editor need easy access
to local drives and while development changes should hot-reload quickly.

### Packaged Local Mode

Packaged local mode is the default for non-developer users:

- Docker Compose starts the database, backend, separate interactive, scan, and
  audio-analysis queue workers, the scheduler, and a web server.
- The frontend is built once and served as static files by nginx, which proxies
  `/api` requests to Laravel.
- The browser opens a single local URL, for example `http://127.0.0.1:8080`.
- Laravel, the queue worker, and PostgreSQL are not exposed directly.
- Music folders are mounted into the backend container as read/write or
  read-only volumes depending on which features are enabled.
- Protected settings APIs are allowed through the local nginx proxy only in
  local packaged mode. LAN mode disables that local-proxy shortcut and uses the
  admin-token boundary instead.

The ordinary packaged `analysis` queue worker remains idle when Audio
Intelligence is disabled. Explicit optional setup builds the selected analyzer
image, records a separately reviewed model mount, stops the ordinary worker,
and starts a profiled `queue-analysis-ai` worker. Only that worker receives the
Docker socket and read-only model/music mounts. Analyzer health checks are sent
through its queue, so the web backend does not need Docker control access.
CPU is the portable default; CUDA is built and selected only on request.

The runtime for this mode is defined in:

- `compose.packaged.yaml`
- `.env.packaged.example`
- `backend/Dockerfile.packaged`
- `frontend/Dockerfile.packaged`
- `docker/packaged/nginx.conf`
- `scripts/configure-packaged-audio-intelligence.ps1`
- `sonotheque`

It is intentionally separate from the current development `compose.yaml`.

### Packaged LAN Mode

LAN mode is an explicit manual choice:

- The app binds only to a selected private IPv4 address.
- Settings, scans, folder browsing, metadata editing, and other administrative
  actions require the admin token from remote clients.
- The startup script prints the LAN URL and the narrow Windows Firewall rule.
- Nothing is registered to start with Windows unless a future installer adds a
  clearly labelled option.

## Docker Volume Model

Music folders do not need to live inside the Docker image. They should be
mounted into the container.

The catalog folder view can browse only within these configured mounts. It uses
library-root IDs and relative paths, so host paths are not exposed to the
browser and stable `/music/root-N` mappings continue to work after migration or
host-folder changes.

Example concept:

```text
Host path              Container path
G:\Music               /music/root-1
H:\Archive             /music/root-2
D:\Rips                /music/root-3
```

The app should store the path that the scanner can access. For a packaged
container setup that means the container path, while the UI should also show the
friendly host label when it is known.

The folder browser must browse the mounted container paths in packaged mode.
That means the setup process needs a small mount configuration step before the
first library root can be selected:

- Name the root, for example `Main Library`.
- Select or enter the host folder.
- Map it to a stable container path such as `/music/main`.
- Restart or recreate the affected container when the Compose volume list
  changes.
- Then let the in-app folder browser pick folders beneath that mounted path.

For safety, user-provided mount targets should be confined to a known container
prefix such as `/music`.

The base packaged Compose file provides a first host-folder mount at:

```text
/music/root-1
```

To try the skeleton manually, copy `.env.packaged.example` to `.env.packaged`,
set `APP_KEY`, set `SONOTHEQUE_ROOT_1` to a real music folder, then run:

```powershell
docker compose --env-file .env.packaged -f compose.packaged.yaml up -d --build
```

Open:

```text
http://127.0.0.1:8080/
```

The portable release wraps this flow in `Start Sonotheque.cmd`. On first
start it opens a native folder picker, generates missing secrets, starts the
Compose stack, and opens the setup wizard. Later starts reuse `.env.packaged`
and the existing Docker volumes.

The packaged scripts are now available:

```powershell
.\scripts\start-packaged.ps1 -MusicRoot "G:\Music"
.\scripts\status-packaged.ps1
.\scripts\stop-packaged.ps1
```

The portable launcher opens a native folder picker repeatedly on first start.
It records the host folders and stable container mappings in the ignored
`packaged-roots.json` file and generates an ignored
`compose.packaged.override.yaml` file. The override mounts every configured
root into the backend, queue, scheduler, and migration services. Users can run
`Configure Sonotheque Folders.cmd` later to append folders or replace the full
list without editing Compose YAML.

Mount order is significant. Existing host folders should retain their current
position because `/music/root-N` paths are stored by the catalog. New folders
should normally be appended. Removing or reordering a mount requires reviewing
the corresponding library roots in Settings and rescanning affected roots.

The start script creates `.env.packaged` when missing, generates stable local
secrets once, runs migrations, starts the Compose services, and prints the app
URL. By default it binds to `127.0.0.1` and enables
`SONOTHEQUE_LOCAL_PROXY_ENABLED` so the packaged nginx proxy can access local
settings APIs. LAN mode is still explicit:

```powershell
.\scripts\start-packaged.ps1 -Lan -LanAddress 192.168.1.10 -MusicRoot "G:\Music"
```

In LAN mode the script generates an admin token if one is not configured and
prints the narrow Windows Firewall rule to run from an elevated PowerShell
window if another device cannot connect. LAN mode disables
`SONOTHEQUE_LOCAL_PROXY_ENABLED`, so settings, scan, folder-browsing, and
metadata-edit operations require the admin token from remote clients.

After startup, maintainers can run the same non-destructive LAN preflight used
for development mode:

```powershell
.\scripts\verify-lan.ps1 -Mode Packaged
```

It reads the packaged address, port, and token from `.env.packaged`, checks
public and protected HTTP behavior without displaying the token, and prints the
physical-device checklist. Run it from an elevated PowerShell window when the
firewall-rule check should be included.

## Migrating From Development Mode

The development runtime and packaged runtime use separate PostgreSQL volumes.
To keep existing playlists, favorites, play counts, personal album information,
settings, and scan history, migrate the development database into the packaged
database instead of starting fresh.

The helper script is intentionally guarded because it replaces the packaged
database contents:

```powershell
.\scripts\migrate-dev-to-packaged.ps1 `
  -RootMap "G:\Music=/music/root-1" `
  -Force
```

Use one `-RootMap` entry for each configured library root. The left side must
match the path stored by the development app. The right side must be the mounted
container path visible to packaged mode, currently `/music/root-1` for the first
mount.

The script:

- copies the development `APP_KEY` into `.env.packaged` so encrypted settings
  remain readable;
- dumps the current development PostgreSQL database;
- restores it into the packaged PostgreSQL volume;
- remaps `library_roots.path` and `library_roots.path_hash` to container paths;
- restarts packaged app services unless `-NoRestart` is used.

After migration, verify the displayed library roots and run a rescan in
packaged mode. The helper migrates database state only. Generated thumbnails can
be recreated, and durable metadata-backup files should be handled separately if
they matter for a particular installation.

## First-Run Setup

When the app starts with no configured library roots, it should present a guided
setup flow:

1. Runtime check: database, queue worker, storage, and mounted music paths.
2. Library root setup: add one or more folders and cover-path candidates.
3. Scan exclusions: optionally select folders to ignore.
4. Metadata settings: keep tag writing, statistics synchronization, and rating
   synchronization disabled by default, with clear explanations.
5. Optional connections: Last.fm and online enrichment can be skipped.
6. First scan: start the scan and explain the counting phase before metadata
   parsing begins.

The setup flow should be resumable. If the browser closes halfway through, the
user should return to the incomplete step rather than start over.

## Health And Diagnostics

A System or Health tab in Settings should show:

- Backend health.
- Database connectivity.
- Queue worker status.
- Scheduler status, if added.
- Writable storage directories.
- Configured library roots and whether each path is reachable.
- Mounted root mappings in packaged mode.
- Last scan state and whether it is actively progressing.
- Recent failed queue jobs with friendly explanations.

This would turn common failure modes into visible status instead of making the
user infer them from stalled scans or empty pages.

## Startup Scripts

The existing manual Windows scripts should remain, but the packaged path should
add a user-facing wrapper:

- `scripts/start-packaged.ps1`
- `scripts/stop-packaged.ps1`
- `scripts/status-packaged.ps1`

The startup script should:

- Check that Docker Desktop is reachable.
- Create `.env` from an example file if needed.
- Generate stable secrets only once.
- Start the Compose stack.
- Run migrations.
- Print and optionally open the app URL.
- Generate a LAN admin token once when LAN mode is requested and no token is
  configured yet. (Complete)

The stop script should stop only the Compose services owned by this project.

## Backups

The app avoids moving or deleting music files. The normal workflow is available
under Settings > System: choose a destination folder, create a single
`.sonotheque-backup` archive, or select one of those archives for a guarded
restore. The UI validates checksums and the encryption key before confirmation,
creates a safety backup, temporarily enables maintenance mode, restores data,
runs migrations, and automatically rolls back if the restore fails.
Backup and restore work is serialized with library scans, so an operation may
remain queued until the active scan has finished.

Packaged mode exposes the host's `${SONOTHEQUE_BACKUP_DIRECTORY:-./backups}` as
`/backups`; this is the default folder shown by the Settings picker. Both
packaged launchers create the default host `backups` folder before starting the
containers. Set `SONOTHEQUE_BACKUP_DIRECTORY` in `.env.packaged` to expose a
different host folder. Manual
application-data backup and restore commands remain available for recovery and
for importing a backup whose `APP_KEY` differs from the current installation:

```powershell
.\scripts\backup.ps1 -Mode Packaged
.\scripts\restore.ps1 -BackupPath ".\backups\sonotheque-packaged-..." -Mode Packaged -Force
```

The Linux/macOS packaged equivalents use the same bundle format:

```sh
./sonotheque backup
./sonotheque restore "backups/sonotheque-packaged-..." --force
```

Each bundle contains a PostgreSQL custom-format dump, an uncompressed storage
archive for fast handling of already-compressed artwork, the Laravel `APP_KEY`,
and a manifest with SHA-256 hashes. Restore validates the
bundle and runtime mode before changing data, creates a safety backup by
default, stops application writers, restores database and storage, runs
migrations, and returns previously running services to service.

Music files and source cover images are not included. Metadata-edit backups
stored in an explicitly configured external directory require a separate
filesystem backup. Settings > System shows the latest completed system backup
or restore recorded for the installation.

Automated backup scheduling can wait; manual backup and restore commands should
remain the explicit default.

## Implementation Phases

### Phase 1: Documented Packaged Architecture

- Keep the current development runtime unchanged.
- Add this setup and distribution plan. (Complete)
- Add a production Compose design with services, ports, volumes, and secrets.
  (Initial skeleton complete)
- Decide whether the web entry point is Laravel serving static frontend assets
  or a small web server container proxying to PHP-FPM. (Initial decision:
  nginx serves built Vue assets and proxies `/api` to Laravel)

### Phase 2: Production Compose Skeleton

- Add backend and frontend Dockerfiles. (Complete)
- Build frontend assets during the image build. (Complete)
- Serve the app through one HTTP port. (Complete)
- Add separate backend, queue worker, scheduler, PostgreSQL, and web services.
  (Complete)
- Keep music folders configurable as Compose volumes. (Complete with generated
  multi-root override configuration)
- Add `.env.packaged.example` with commented defaults. (Complete)

### Phase 3: Packaged Startup Scripts

- Add Windows scripts that start, stop, and inspect the packaged stack.
  (Initial complete)
- Generate missing secrets once. (Complete)
- Run migrations on startup. (Complete)
- Print clear local and LAN URLs. (Complete)
- Keep LAN startup explicit and token-protected. (Complete)
- Add a guarded development-to-packaged database migration helper that preserves
  APP_KEY and remaps library-root paths. (Initial complete)

### Phase 4: First-Run Setup UI

- Detect incomplete setup. (Complete)
- Add a guided setup page for library roots, cover paths, scan exclusions,
  metadata settings, and optional connections. (Complete)
- Add explanatory scan progress for the counting phase. (Complete)

### Phase 5: Health Page

- Add backend health endpoints for database, queue, scheduler, storage, roots,
  and failed jobs. (Complete)
- Add a Settings > System tab that shows these checks. (Complete)
- Provide direct recovery hints for common issues. (Complete)

### Phase 6: Backup And Restore

- Add database backup and restore scripts. (Complete)
- Add storage backup guidance. (Complete)
- Surface backup status and restore guidance in Settings. (Complete)

### Phase 7: Installer Or Portable Bundle

- Package the repository, scripts, default env files, and docs as a versioned
  archive. (Initial portable bundle complete)
- Add double-click Windows launch, stop, and status wrappers. (Complete)
- Add a first-launch host music-folder picker. (Complete for multiple roots)
- Add stable multi-root host-folder configuration without manual YAML editing.
  (Complete)
- Generate a SHA-256 checksum beside each release archive. (Complete)
- Publish tagged portable archives through a verified GitHub Actions workflow.
  (Complete; version `v0.1.0` published)
- Later, consider a Windows installer that can create shortcuts and optionally
  register a manual Start Menu entry.

### Phase 8: Cross-Platform Packaged Distribution

- Extract secret generation, environment updates, root validation, stable
  `/music/root-N` mapping, Compose override generation, and worker selection
  from PowerShell into one platform-neutral setup core. Keep the existing
  Windows wrappers as thin adapters. (Configuration generation complete;
  worker selection remains in the launch adapters)
- Add a POSIX `sonotheque` command for start, stop, status, root configuration,
  Audio Intelligence configuration, backup, restore, and explicit LAN mode.
  (Complete)
- Accept terminal-entered or drag-and-dropped paths everywhere. Add optional
  native pickers with graceful fallback: WinForms on Windows, `osascript` on
  macOS, and `zenity` or `kdialog` on Linux when available. (Complete for
  packaged root and Audio Intelligence model selection)
- Add configurable Linux UID/GID handling so playlist exports, metadata backup,
  and other writes do not create unexpectedly root-owned host files. Keep
  Docker socket access restricted to the dedicated analysis worker. (Complete)
- Add `host.docker.internal:host-gateway` support for Linux and document the
  corresponding Ollama listen-address requirement. (Compose support complete;
  setup guidance complete)
- Add platform-specific browser launch and LAN/firewall guidance without
  automatically modifying host firewall rules.
- Validate external and removable-drive mounts on Linux and macOS, including
  Docker Desktop file-sharing requirements on macOS.
- Publish Windows ZIP and Linux/macOS TAR archives with checksums and matching
  installation guides. Keep the Compose application payload shared. (Archive,
  checksum, release workflow, and initial installation guide complete)
- Add Ubuntu CI coverage for POSIX scripts, Compose generation, first-run setup,
  upgrade preservation, scanning, and playback. Add manual or self-hosted macOS
  smoke coverage because hosted macOS CI does not represent Docker Desktop.
  (POSIX configuration, backup validation, launcher/Compose checks, and shared
  packaged browser/upgrade workflows complete on Ubuntu; macOS remains manual)
- Define and test the support matrix: base Sonotheque on x86-64 and ARM64 where
  its images permit; CPU Audio Intelligence where Essentia/TensorFlow images
  build natively; NVIDIA CUDA only on supported Windows/Linux hosts; no CUDA on
  macOS. Unsupported analysis hardware must leave the rest of Sonotheque fully
  usable with the feature disabled. (Initial explicit matrix published in
  `docs/platform-support.md`; ARM64 and macOS analysis remain experimental)

## Packaged Browser Verification

The packaged browser workflow has a Playwright suite that starts an isolated
Compose project and an empty application database. Its prerequisite setup
project configures a mounted library root and ordered cover paths through the
first-run UI, persists optional metadata settings across a refresh, verifies
that online connections remain opt-in, scans two generated music fixtures
through the real queue worker, and completes setup. Dependent projects then
exercise folder navigation, actions, streaming, and playback in Chromium.
A separate upgrade suite populates the published `v0.1.0` package, retains its
PostgreSQL and application-storage volumes, starts the current package over
them, and verifies that migrations and user data survive.

The suites use `http://127.0.0.1:18080` and `http://127.0.0.1:18081`, own
separate Docker volumes, and remove their containers, volumes, and generated
fixtures when they finish. They do not use the development or normal packaged
database. The upgrade suite requires the `v0.1.0` Git tag to be available.

Install the browser once and run the suite from `frontend` while Docker Desktop
is available:

```powershell
npx playwright install chromium
npm run test:e2e:packaged
npm run test:e2e:upgrade
```

The tagged-release workflow fetches the repository tags, installs Chromium,
and runs both suites after the normal frontend checks. The browser suite covers
empty-install redirection, required-root enforcement, root and cover-path
configuration, resumable setup settings, first scanning, folder navigation,
large-folder confirmation, subtree-scan cancellation, range streaming,
play/pause, seeking, switching after a seek, queue progression, previous/next
controls, and playback restoration after refresh. The upgrade suite preserves library roots, catalog records,
favorites, playlists, personal album metadata, settings, first-run state,
application storage, and stable mounted paths while applying all current
migrations.

## Publishing A Release

Release publication is intentionally tag-driven. Ordinary branch pushes never
create downloadable releases.

Ongoing work is integrated into `development`; `master` contains released
checkpoints. Short-lived branches, when needed, start from and return to
`development`.

1. On `development`, update `VERSION`, `frontend/package.json`, and both
   version entries in `frontend/package-lock.json` to the same semantic
   version.
2. Add a dated, non-empty `## X.Y.Z` section to `CHANGELOG.md`. That section is
   the canonical release-note reference and becomes the GitHub Release
   description.
3. Run the release validation and relevant application tests on
   `development`.
4. Merge or fast-forward the validated release commit directly into `master`
   and push `master`.
5. Create the annotated tag on that exact `master` commit and push it:

```powershell
git switch master
git merge --ff-only development
git push origin master
git tag -a v0.2.0 -m "Sonotheque 0.2.0"
git push origin v0.2.0
```

The `Publish Sonotheque Release` workflow then runs the PostgreSQL-backed PHP
suite, PSR-12 and PSR-4 checks, frontend lint/tests/build, packaged folder and
upgrade suites, and the Windows portable package build. It verifies the archive
contents and SHA-256 checksum before creating the GitHub Release from the
matching changelog section.

Maintainers can reproduce the package checks locally without publishing:

```powershell
.\scripts\assert-release-version.ps1 -Tag v0.2.0
.\scripts\build-release.ps1 -Version 0.2.0
.\scripts\verify-release.ps1 -Version 0.2.0
```

## Open Decisions

- Whether packaged mode should write metadata tags by default as read/write
  mounts, or mount music folders read-only until metadata writing is enabled.
- Whether a future installer should provide a richer mount editor with direct
  removal and reordering safeguards beyond the generated Compose override.
- Whether a future installer should replace the portable archive once upgrade,
  shortcut, uninstallation, and migration requirements justify it.

The initial deployment decisions are now settled: `v0.1.0` uses nginx for the
built frontend and Laravel API proxy, defaults to localhost, and supports LAN
mode only through an explicit token-protected startup option.

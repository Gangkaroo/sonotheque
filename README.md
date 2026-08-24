# Sonotheque

Sonotheque is a local-first web application for organizing, exploring, and
playing large digital music collections. It scans music stored across multiple
drives, builds a searchable catalog, and keeps the original files in their
existing folders.

The application is designed for collectors whose digital library may include
backups of physical media. It runs locally by default and can also be
made available on a trusted local network.

> **AI development disclosure:** Sonotheque and its documentation have been
> generated substantially through iterative collaboration with OpenAI Codex.
> The generated code is reviewed, tested, and refined as part of development,
> but the project should still be treated as actively evolving software.

## Features

### Library management

- Scan one logical library across multiple local folders and drives.
- Incrementally rescan changed files, monitor configured roots, and reconcile
  files moved between roots without losing their catalog identity.
- Browse artists, albums, tracks, genres, musicians, and the physical folder
  structure with root-scoped filtering, search, sorting, and pagination.
- Discover folder or embedded artwork and generate compact thumbnails without
  copying original cover files into application storage.
- Review unavailable tracks in a trash view before permanently deleting their
  catalog records.

### Metadata and personal collection data

- Read common audio metadata and ID3 tags with tolerant fallbacks for malformed
  files.
- Preview and apply individual, album-wide, or selected-track metadata edits,
  including verification and optional backups.
- Store album notes, owned physical copies, format, purchase source and date,
  condition, and Discogs release links.
- Rate albums and tracks from 0.5 to 5 stars in half-star increments, with
  optional MP3 tag synchronization.
- Enrich albums and artists with MusicBrainz, Discogs, Last.fm, and lyrics data,
  using provider-aware caching and background refreshes.
- Collect and manually refine musician credits associated with albums.

### Playback and playlists

- Stream local audio with seeking, persistent playback state, queue controls,
  continuous or random playback, Media Session controls, and an optional audio
  visualizer.
- Create folder-organized playlists with drag-and-drop ordering, favorites,
  listening history, and playback statistics.
- Import, export, and optionally synchronize M3U/M3U8 playlist files.
- Preserve filtered album or track scopes for sequential and random playback.

### Optional local intelligence

- Analyze tracks locally to calculate musical features and embeddings for
  similar-track search, mood continuation, and similarity-based playlist
  ordering.
- Compare CPU and CUDA analysis performance and resume long-running,
  root-scoped analysis without repeating completed work.
- Ask an optional Ollama-powered Collection Assistant questions about the local
  catalog and listening history through a restricted tool layer.
- Preview assistant-proposed similarity playback actions and require explicit
  confirmation before changing the browser queue.

Audio Intelligence and the Collection Assistant are independent, disabled by
default, and never download models or analyze music implicitly.

## Technology stack

### Backend

- PHP 8.5 development runtime
- Laravel 13 and API Platform for Laravel
- PostgreSQL with pgvector
- Laravel queues and scheduler
- getID3, FFprobe/FFmpeg, and GD for media metadata and artwork processing

### Frontend

- Vue 3 with TypeScript
- Pinia, Vue Router, and Vue I18n
- Vuetify and Material Design Icons
- Tiptap for rich-text album notes
- Vite, Vitest, ESLint, and Playwright

### Runtime and packaging

- Docker Compose for PostgreSQL during development
- A portable Docker Compose stack with nginx, Laravel, workers, scheduler,
  PostgreSQL, and the built Vue application
- Windows launchers plus a shared Linux/macOS `sonotheque` command for packaged
  startup, shutdown, status, browser launch, and music-folder configuration

## Getting started

The simplest supported user setup is a portable Docker package:

1. Install Docker Desktop or Docker Engine with Compose v2.
2. Download and extract a Sonotheque release into a permanent folder.
3. On Windows, run `Start Sonotheque.cmd`. On Linux or macOS, run
   `./sonotheque start`.
4. Select the host folders containing the music library.
5. Complete the setup wizard and start the first scan.

Music folders are mounted into the containers; the music itself is not copied
into the Sonotheque package. See [INSTALL.md](INSTALL.md) for the complete user
installation and upgrade workflow.

The Linux/macOS packaged path now shares environment, secret, and mount
generation with Windows, preserves Linux host ownership, and is published as a
portable TAR archive. It includes optional native folder/model pickers,
checksummed backup and guarded restore commands, guided Audio Intelligence
provisioning, and POSIX release smoke coverage. See
[the platform support matrix](docs/platform-support.md) for tested and preview
host/architecture combinations.

For development, PostgreSQL runs in Docker while Laravel, its queue workers,
and Vite run on the Windows host. See [docs/runtime.md](docs/runtime.md) for the
required PHP runtime and development commands.

## Optional services

- **Last.fm:** authorization, now-playing updates, and scrobbling.
- **Discogs:** owned-release matching and collection information.
- **MusicBrainz:** artist, album, and musician enrichment.
- **LRCLIB:** synchronized or plain lyrics where available.
- **Ollama:** local Collection Assistant with an explicitly selected model.
- **Local audio analyzer:** optional CPU or NVIDIA CUDA audio embeddings.

Normal catalog browsing, scanning, and playback do not require these services.

## Documentation

- [Installation](INSTALL.md)
- [Development runtime](docs/runtime.md)
- [Setup and distribution](docs/setup-and-distribution.md)
- [Audio Intelligence](docs/audio-intelligence.md)
- [Collection Assistant](docs/collection-assistant.md)
- [Implementation plan](docs/implementation-plan.md)
- [Release notes](CHANGELOG.md)

## Security and scope

Sonotheque binds to localhost by default. LAN mode is an explicit manual choice
and protects administrative operations with an admin token. Direct Internet
exposure and trusted-device enrollment remain deferred roadmap work; the
current application should not be exposed directly to the public Internet.


# Local Music Library - Implementation Plan

## Goal

Build a local-first web application that scans configurable music folders, stores metadata in PostgreSQL, and provides a responsive browser and player for the library. The application will run on the local computer or, when explicitly enabled, on the local network. User management is outside the initial scope.

## Technology Stack

### Backend

- PHP 8.5
- Laravel 13
- API Platform 4.3 for Laravel with Eloquent
- PostgreSQL 18
- Laravel database queues for background scans
- getID3 for audio metadata, tags, technical information, and embedded artwork

### Frontend

- Vue 3 with TypeScript
- Vite
- Pinia
- Vue Router
- Vue I18n
- Vuetify 4

### Development and Runtime

- Monorepo containing backend, frontend, infrastructure, and documentation
- PostgreSQL in Docker for local development
- PHP running natively on Windows initially, so the scanner can access dynamically configured local folders

## Proposed Repository Structure

```text
music-library/
|-- backend/          Laravel and API Platform
|-- frontend/         Vue application
|-- infrastructure/   Docker and supporting configuration
|-- docs/             Architecture and project documentation
`-- compose.yaml
```

## MVP Scope

The first usable release will provide:

- Configuration of one or more local music folders
- Manual and incremental library scans
- Scan status, progress, warnings, and errors
- Browsing by artist, album, track, and genre
- Search, filtering, sorting, and pagination
- Artist A-Z/# browsing based on a normalized, indexed initial
- Album, artist, track, and genre search
- Album filtering by artist initial, original release year, and genre
- Track filtering by genre
- Cross-navigation from artists and genres to filtered album and track lists
- Album artwork discovery from a configurable path relative to each album folder
- Cached, smaller album-cover thumbnails for album lists and grids
- Embedded album artwork extraction as a fallback
- Album detail pages with full-size artwork, track listing, album genres, and artwork overlay
- Browser playback for supported audio formats
- Persistent playback controls, seeking, volume, current-page queue navigation, and random playback actions
- Continuous album and track playback with optional random next-item selection
- Visible current playback queue with album and track queue actions
- Favorite tracks and favorite albums with browse sections
- English and German translations
- Localhost access by default and optional LAN access

The following features are deferred until after the MVP:

- Editing or writing audio tags
- User accounts and permissions
- Persisted custom playlists, playlist folders, favorite tracks, and favorite albums
- Automatic metadata lookup from external services
- Audio transcoding
- Duplicate-file management
- Mobile applications

## Architecture

The Vue single-page application communicates with the Laravel application through an API Platform API. Laravel owns library configuration, scanning, metadata normalization, search, artwork delivery, and audio streaming.

Scanning is performed asynchronously using Laravel queue jobs. Each discovered file is inspected with getID3 and normalized into relational library records. Original tag data is retained in JSONB for diagnostics and future metadata fields.

For the initial library layout, each configured library root is expected to contain artist folders, with album folders beneath them:

```text
library-root/
`-- Artist/
    `-- Album/
        |-- configured/relative/cover.jpg
        |-- 01 - Track.mp3
        `-- 02 - Track.mp3
```

The cover-image path is configured per library root and resolved relative to each album folder. For example, a value of `cover.jpg` refers to a file directly in the album folder, while `artwork/front.jpg` refers to a nested file. When the configured file is absent or invalid, the scanner falls back to embedded artwork from the album's audio files.

Audio is delivered through a dedicated streaming endpoint supporting HTTP range requests. The server must verify that every requested file belongs to an enabled library root before reading it.

## Data Model

### `libraries`

Logical music libraries. A library can span any number of physical roots located on different drives.

- Name and optional description
- One-to-many relationship with library roots

### `library_roots`

Configured folders and their scan settings:

- Parent library
- Path and display name
- Enabled state
- Include/exclude patterns
- Cover-image path relative to each album folder
- Last successful scan time

### `scan_runs`

Scan execution history:

- Status and progress counters
- Start and completion times
- Trigger type
- Warning and error summaries

### `media_files`

Physical file information:

- Library root and relative path
- Canonical path or path fingerprint
- File size and modification time
- MIME type, container, codec, bitrate, and sample rate
- Scan state and last-seen timestamp
- Raw metadata in JSONB

### Library entities

- `tracks`
- `artists`
- `albums`
- `genres`
- Track-artist and track-genre pivot tables

Tracks contain normalized values such as title, duration, year, track number, disc number, and links to the source media file.

Artists store a normalized sort name and an indexed browse initial. The browse initial contains `A` through `Z`; accented Latin initials are transliterated into those buckets, while names beginning with numbers, symbols, or other characters are grouped under `#`. Albums store the original release year separately from track-level tag data. PostgreSQL trigram indexes support case-insensitive partial searches for artist, album, and genre names. Genre names are unique without regard to letter case so filter values do not fragment into variants such as `Rock` and `rock`.

### `artwork`

Cached artwork metadata:

- Source type and original source path
- Cache path
- Thumbnail cache path
- MIME type
- Width and height when available
- Checksum for deduplication

Artwork should be cached as files rather than stored as PostgreSQL binary data. The original-size cached image is used on album detail pages, while a generated thumbnail is used in album lists and grids. Thumbnail dimensions and image quality should be application-level configuration with sensible defaults.

### Playback queue

The active playback queue is a runtime concept owned by the frontend player store for the first milestone. It contains ordered track snapshots, the current index, playback context, playback position, and player settings. It is persisted in browser storage so a refresh can restore the current player state, but it is not yet a server-side library entity.

The queue UI should be designed as a stepping stone toward playlists:

- Display the current queue and highlight the active track.
- Allow jumping to a queued track.
- Allow removing queued tracks.
- Add queue actions from album, track list, and track detail contexts.
- Preserve the distinction between "play now" and "add to queue".
- Keep the queue data shape close to future playlist item data: ordered track references plus display metadata.

### Future user collection entities

Playlists and favorites are intentionally deferred until after the first milestone, but the queue and playback UI should leave room for them.

Planned persisted entities:

- `playlist_folders`: optional folder hierarchy for organizing playlists.
- `playlists`: user-created named track collections, optionally assigned to a folder.
- `playlist_items`: ordered track references inside a playlist, with position and timestamps.
- `favorite_tracks`: track-level favorites.
- `favorite_albums`: album-level favorites.

Early local-only builds have no user accounts, so favorites and playlists can initially be global to the local installation. If user management is introduced later, these tables can gain an owner column without changing the core catalog entities. Favorites should reference catalog entities rather than duplicate metadata, so rescans keep favorites attached as long as the track or album identity remains stable.

## Implementation Phases

### Current Status

Completed:

- Laravel/API Platform backend and PostgreSQL development environment
- Database schema, relationships, browse/search indexes, and read-only catalog APIs
- Incremental filesystem scanner with queued execution and error isolation
- getID3 metadata extraction and normalized artist, album, track, and genre records
- Folder-cover discovery, embedded-artwork fallback, artwork caching, and thumbnail generation
- Vue/Vuetify application shell with responsive navigation, Pinia, routing, and English/German translations
- Library-root list, create, and remove workflow with canonical path and safe relative cover-path validation
- Scan start/cancel API, queued dispatch, progress/history API, and periodically refreshed Settings UI controls
- Structured scan diagnostics for invalid layouts, unreadable entries, malformed files, missing files, and unavailable roots
- Safe handling for suspicious empty rescans so an unavailable drive does not mark the existing catalog missing
- Read-only Explorer-style server folder browser for selecting library roots
- Batched scan progress, cancellation checks, incremental fingerprints, and repeated metadata lookup caches
- Raw tag metadata sanitation for binary ID3 payloads that PostgreSQL JSONB cannot represent
- Dashboard metrics with lightweight aggregate queries
- Paginated artist, album, track, and genre browsing with server-side search and artist A-Z/# filtering
- Album grids with cached thumbnail delivery and missing-artwork placeholders
- Secure audio streaming with HTTP range support and enabled-root/path validation
- Album detail pages with full-size artwork, genre display, track listing, and clickable artwork overlay
- Album and track list filters for artist, release year, and genre
- Cross-links from artists and genres to filtered album and track lists
- Clickable player metadata, artist links, album links, and track-list artist/album links
- Track detail pages with technical metadata, playback action, album-aware back navigation, and track-title links from track and album lists
- Persistent browser playback controls with seeking, volume, explicit playback state, current-track navigation, random album/track actions, continuous play, and optional random continuation
- Visible current queue drawer with jump/remove actions plus queue album and queue track actions
- Favorite track and favorite album persistence, buttons, and browse sections
- Playlist folder, playlist, and ordered playlist-item persistence APIs
- Playlists navigation page with folder and playlist creation/deletion

In progress or still required for the first milestone:

- Queue worker startup documentation and local runtime integration
- Add-to-playlist actions from tracks, albums, queue entries, and the player
- Runtime documentation for local startup, queue worker, Docker database, scanning, and troubleshooting

The implementation order changed slightly from the original phase list. The scanner and artwork pipeline were completed before the catalog frontend, and the manual library-root configuration and scan-management workflows were brought forward so a real scan can be exercised end to end. Catalog browsing is now connected to paginated, purpose-built API endpoints. The next vertical slice is secure audio streaming and browser playback.

### 1. Project Foundation

- Scaffold the Laravel/API Platform backend.
- Scaffold the Vue, TypeScript, Vuetify frontend.
- Configure Pinia, Vue Router, and Vue I18n.
- Add PostgreSQL and local infrastructure configuration.
- Establish development, linting, formatting, and test commands.
- Configure the API under an `/api` prefix.

### 2. Database and API

- Create migrations and Eloquent models.
- Define relationships and indexes.
- Expose read-only library resources through API Platform.
- Add pagination, filters, ordering, and search parameters.
- Add artist A-Z/#, original-release-year, and genre filters.
- Define custom operations for settings and scans.
- Verify the generated OpenAPI documentation.

### 3. Filesystem Scanner

- Validate and canonicalize configured folders.
- Discover supported audio files recursively.
- Parse tags and technical metadata using getID3.
- Normalize artists, albums, tracks, and genres.
- Associate files using the configured `artist/album` folder layout while retaining tag metadata for display and diagnostics.
- Resolve and validate the configured cover-image path inside each album folder.
- Cache full-size album artwork and generate a smaller thumbnail.
- Fall back to embedded artwork when no configured folder image is available.
- Deduplicate cached artwork using checksums.
- Detect unchanged files using size and modification time.
- Update modified files and mark missing files as unavailable.
- Record malformed files and nonfatal warnings without stopping a scan.
- Execute scans through queued jobs.

### 4. Library Frontend

- Create the application shell and navigation.
- Build dashboard, artist, album, genre, and track views.
- Add global search, filters, sorting, and pagination.
- Add responsive artwork grids and tabular track views.
- Display the generated cover thumbnail for every album in album lists and grids.
- Display the larger cached cover on album detail pages.
- Add album detail pages with album artwork overlay, album-level genre chips, play-album action, and highlighted current track.
- Add artist and genre action icons that open filtered album and track lists.
- Add clickable artist and album metadata in track lists.
- Provide useful placeholders for missing artwork and metadata.
- Add English and German translations from the beginning.

### 5. Audio Playback

- Implement an audio streaming endpoint with HTTP range support. (Complete)
- Validate file access against enabled library roots. (Complete)
- Add persistent player controls. (Complete)
- Add playback queue management using Pinia. (Current-page queue complete)
- Show and manage the visible current queue. (Complete)
- Add "queue album" and "queue track" actions beside "play now" actions. (Complete)
- Add random album and random track actions. (Complete)
- Add continuous play and random continuation settings. (Complete)
- Continue by album when album playback reaches the end, and switch visible album detail pages accordingly. (Complete)
- Continue by track when track-list playback reaches the end. (Complete)
- Make player metadata navigable: track title opens track detail, album opens album detail, artist opens filtered albums, and "Now playing" jumps to the current track. (Complete)
- Handle unavailable files and unsupported browser codecs clearly.
- Decide and implement the track-title navigation model for track-centric playback. (Complete)
- Consider FFmpeg-based transcoding only after the MVP.

### 5a. Playlists and Favorites

This phase is planned after the first milestone. It should build on the queue model rather than replace it.

- Add favorite buttons to track detail, album detail, track lists, album lists, and player affordances. (Complete)
- Add favorite track and favorite album browse sections. (Complete)
- Add a playlist navigation section. (Complete)
- Add playlist folders for organizing custom playlists. (Foundation complete)
- Add playlist create, rename, move-to-folder, delete, and reorder workflows.
- Add playlist create and delete workflows. (Foundation complete)
- Add ordered playlist item API for adding, removing, and reordering tracks. (Complete)
- Add "add to playlist" actions from tracks, albums, queue entries, and the player.
- Allow creating a playlist from the current queue.
- Consider importing/exporting playlists only after the core local workflow is stable.

### 6. Settings and Scan Management

- Add and remove library roots. (Complete)
- Provide manual path input. (Complete)
- Provide a restricted server-side folder browser. (Complete)
- Configure the album-cover path relative to album folders for each library root. (Complete)
- Validate the cover path as a safe relative path. (Complete)
- Show an example resolved cover location. (Pending)
- Start, cancel, and repeat scans. (Complete)
- Display live or periodically refreshed scan progress. (Complete)
- Show last-scan information and actionable file errors. (Complete)

### 7. Local and LAN Security

- Bind services to `127.0.0.1` by default.
- Require an explicit configuration change for LAN access.
- Prevent path traversal and symbolic-link escapes.
- Reject absolute or escaping album-cover paths such as paths containing unresolved `..` segments.
- Restrict folder browsing to configured drives or parent directories.
- Configure CORS and trusted hosts narrowly.
- Before LAN settings access is enabled, add a shared administrative token or restrict settings operations to localhost.

### 8. Testing and Packaging

- Unit-test path validation, metadata mapping, and incremental scan decisions.
- Test folder-based cover discovery, embedded-artwork fallback, and thumbnail generation.
- Feature-test API filters, scan operations, and range streaming.
- Use small MP3, FLAC, Ogg, and malformed-file fixtures.
- Add frontend store and component tests.
- Add end-to-end coverage for configuration, scanning, browsing, and playback.
- Document installation, startup, backup, and recovery procedures.

## Recommended Next Step

The next best slice is **add-to-playlist actions**.

Playlist and folder persistence now exists, along with a first Playlists page. The next useful step is connecting catalog and player actions to those playlists.

Recommended scope:

1. Add a reusable "add to playlist" dialog fed by the playlists store.
2. Add track-to-playlist actions from track detail and track list rows.
3. Add album-to-playlist action from album detail that appends all current album tracks in order.
4. Add queue-entry-to-playlist and current-track-to-playlist actions in the player.
5. Add playlist detail pages with ordered track lists, play/queue actions, remove item, and reorder controls.
6. Add "create playlist from queue" after playlist detail and item management are stable.

Design constraints:

- Playlist items should reference `tracks` and store an explicit position.
- Adding an album to a playlist should initially add the current album track order as a snapshot of track references.
- Playlist folders can stay local-installation global records until user management exists.
- Queue actions and playlist actions should share helper logic wherever practical.
## First Milestone Definition

The first milestone is complete when:

1. A local folder can be configured.
2. A background scan discovers supported audio files.
3. Parsed metadata is stored in PostgreSQL.
4. Albums and tracks can be browsed in the Vue interface.
5. Each discovered album displays a generated cover thumbnail from its configured folder image, embedded artwork, or a placeholder.
6. An MP3 can be streamed and played in the browser.
7. Album details, filtered browsing, and playback controls are usable from the main navigation.
8. The main workflow has automated backend and frontend tests.

## Important Early Decisions

- Use Pinia rather than Vuex because Pinia is the recommended state-management library for new Vue applications.
- Use API Platform's Laravel integration with Eloquent.
- Keep PHP native on the host initially to allow access to dynamically selected Windows folders.
- Use PHP 8.5 explicitly for backend commands; an older XAMPP PHP installation may still appear first on the Windows `PATH`.
- Treat library paths as sensitive server-side configuration and never accept arbitrary streaming paths from the browser.
- Make scans incremental from the first implementation rather than adding that behavior later.
- Model the initial library layout as `library root / artist / album`, with the cover path configured relative to the album folder.
- Prefer the configured folder cover over embedded artwork and generate thumbnails during scanning rather than resizing images on every request.

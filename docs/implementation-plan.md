# Sonotheque - Implementation Plan

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

## Repository Structure

```text
sonotheque/
|-- backend/          Laravel and API Platform
|-- frontend/         Vue application
|-- docs/             Architecture and project documentation
`-- compose.yaml
```

## MVP Scope

The first usable release will provide:

- Configuration of one or more local music folders
- Manual and incremental library scans
- Removal of stale catalog records after scans, including deleted, moved, and
  newly excluded files, while preserving records beneath unreadable paths
- Scan status, progress, warnings, and errors
- Browsing by artist, album, track, and genre
- Search, filtering, sorting, and pagination
- Artist A-Z/# browsing based on a normalized, indexed initial
- Album, artist, track, and genre search
- Album filtering by artist initial, original release year, and genre
- Track filtering by genre
- Cross-navigation from artists and genres to filtered album and track lists
- Album artwork discovery from an ordered list of configurable paths relative
  to each album folder
- Explicit library-root subfolder exclusions that prune complete directory trees
- Cached, smaller album-cover thumbnails for album lists and grids
- Embedded album artwork extraction as a fallback
- Album detail pages with full-size artwork, track listing, album genres, and artwork overlay
- Browser playback for supported audio formats
- Persistent playback controls, seeking, volume, current-page queue navigation, and random playback actions
- Lightweight Web Audio API music visualization in the expanded player
- Continuous album and track playback with optional random next-item selection
- Visible current playback queue with album and track queue actions
- Favorite tracks and favorite albums with browse sections
- Custom playlists, playlist folders, ordered playlist items, and queue-to-playlist actions
- Personal album information, including purchase source, purchase date,
  physical-copy state, physical format, and notes
- English and German translations
- Localhost access by default and optional LAN access

The following features are deferred until after the first stable local release:

- User accounts and permissions
- Audio transcoding
- Playlist import/export
- Last.fm history import and now-playing updates
- Mobile applications

## Architecture

The Vue single-page application communicates with the Laravel application through an API Platform API. Laravel owns library configuration, scanning, metadata normalization, search, artwork delivery, and audio streaming.

Scanning is performed asynchronously using Laravel queue jobs. Each discovered
file is inspected with getID3 and normalized into relational library records.
When getID3 reports an error, FFprobe validates the audio stream and supplies
missing technical data and tags; successfully recovered files remain available
with bounded parser warnings. Original parser data is retained in JSONB for
diagnostics and future metadata fields.

For the initial library layout, each configured library root is expected to contain artist folders, with album folders beneath them:

```text
library-root/
`-- Artist/
    `-- Album/
        |-- configured/relative/cover.jpg
        |-- Disc 1/alternate-cover.jpg
        |-- 01 - Track.mp3
        `-- 02 - Track.mp3
```

Cover-image paths are configured as an ordered list per library root and
resolved relative to each album folder. For example, `cover.jpg` refers to a
file directly in the album folder, while `Disc 1/front.jpg` refers to a nested
file. The first existing candidate is used. When none exists or the selected
image is invalid, the scanner falls back to embedded artwork from the album's
audio files. Explicitly excluded directories are stored relative to the library
root and pruned before scan counting and metadata parsing.

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
- Include/exclude patterns and explicit excluded directories
- Ordered cover-image paths relative to each album folder
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

### User Collection Entities

Playlists and favorites are persisted globally for the local installation. There are no user accounts yet, so these records are not owned by a specific user. If user management is introduced later, these tables can gain an owner column without changing the core catalog entities.

- `playlist_folders`: optional folder hierarchy for organizing playlists.
- `playlists`: user-created named track collections, optionally assigned to a folder.
- `playlist_items`: ordered track references inside a playlist, with position and timestamps.
- `favorite_tracks`: track-level favorites.
- `favorite_albums`: album-level favorites.

Favorites and playlist items reference catalog entities rather than duplicating metadata, so rescans keep user selections attached as long as the track or album identity remains stable.

### Personal Album Information

Personal information must be stored separately from scanned album metadata so
rescans and tag edits cannot overwrite it. Initially it is global to the local
installation, like favorites and playlists. A future user-account migration can
add an owner without changing the scanned catalog.

- `album_personal_metadata`: one optional row per album with purchase source,
  purchase date, physical-copy state, physical format, optional personal notes,
  and timestamps.
- Keep purchase source as free text initially; do not create duplicate scanned
  album or artist values for personal information.
- Preserve personal information when a scan updates an album. Apply the same
  orphan/relinking policy used for favorites if an album temporarily disappears
  or is rediscovered.

### Editable Metadata

Tag editing is implemented for ordinary MP3 ID3v2.3/ID3v2.4 files and
losslessly mappable ID3v2.2 files, which are converted to ID3v2.3. It uses
stronger safety guarantees than catalog browsing because it writes back to
music files.

The UI should distinguish album-level metadata from track-level metadata:

- Album-level fields: album title, album artist, original release year/date,
  total discs, album genres, and shared release metadata.
- Track-level fields: track title, track number, disc number, track artist,
  composer, performer, comment, track genres, and other values that can differ
  per file.

The database continues to store scanned metadata as normalized catalog data,
while tag edits are tracked as explicit operations before they are written to
files. `metadata_edit_jobs` and item records store target files, requested
changes, status, progress, error details, backup ownership, and timestamps for
individual, album-wide, and selected-track edits.

Important safety rules:

- Preview the exact file-level changes before writing.
- Write one file at a time and report partial failures.
- Create optional backups before modifying audio files.
- Preserve unknown or unsupported tag frames where the selected tag-writing
  library allows it.
- Re-read the file after writing and update the catalog from the actual file
  contents rather than trusting the submitted form values.

### Listening Statistics

Listening statistics should be database-first and always active. The app should
record plays that happen inside the browser even when tag reading/writing and
external integrations are disabled.

Planned data model:

- `track_play_events`: append-only play history with track ID, media file ID,
  played-at timestamp, playback source, duration listened, and whether the play
  counted toward statistics.
- `track_play_statistics`: per-track aggregate with play count, first played,
  last played, and optional external/source metadata.
- Optional import/export metadata for tag-based playcount fields, including
  source fields, observed values, merge strategy, and future export/conflict
  state.

The app should support importing play statistics from file tags used by tools
such as foobar2000/foo_playcount where the tag format can be identified. Import
and export of listening statistics to file tags are controlled by one
disabled-by-default synchronization setting. Database tracking remains enabled
regardless of that setting.

The first import implementation recognizes foobar2000/foo_playcount fields
such as `PLAY_COUNT`, `FIRST_PLAYED_TIMESTAMP`, and `LAST_PLAYED_TIMESTAMP`, as
well as common legacy aliases and the ID3 play counter. Timestamps support both
textual date values and the Windows FILETIME values written by foo_playcount.
Imports use a
non-decreasing merge: counts never decrease, the earliest first-played value is
retained, and the latest last-played value is retained. The exact imported
values remain in `track_play_statistics.source_metadata` for later conflict
handling. Unchanged files are imported from cached raw metadata, so enabling
the setting does not force a full metadata re-read.

Counted app plays enqueue a coalesced write-back job after a short delay. The
first export implementation supports MP3 ID3v2.3 and ID3v2.4 tags plus
losslessly mappable ID3v2.2 tags through conversion to ID3v2.3, without using
getID3's unsafe generic merge mode: only the `PLAY_COUNT`,
`FIRST_PLAYED_TIMESTAMP`, and `LAST_PLAYED_TIMESTAMP` TXXX frames are replaced,
while unrelated frames and audio bytes are preserved. Values are verified on a
temporary file before it replaces the original, and the scanner fingerprint is
updated afterward. Unsupported formats remain database-only and record that
result in source metadata. M4A and other formats require a preservation-tested
writer before export can be enabled for them.

Writing listening statistics back to file tags should reuse the future
metadata-editing write path: preview changes, optionally back up files, write in
queued jobs, re-read after writing, and report partial failures.

Last.fm support belongs in this same area but should be treated as an optional
integration:

- Store Last.fm credentials/tokens locally and never require them for playback.
- Scrobble eligible app plays after the configured threshold.
- Import recent/history data only when explicitly requested.
- Keep Last.fm state separate from local play statistics so network failures do
  not block local tracking.

### Online Artist, Album, And Lyrics Enrichment

Online enrichment should add context to the currently playing music without
changing scanned tags or becoming a playback dependency. Laravel should call
external providers and expose normalized local API responses; the browser must
not contact providers directly or receive provider secrets.

Provider roles should remain replaceable:

- Use MusicBrainz IDs from file tags when available and MusicBrainz search as a
  fallback for structured artist, release, and recording identity.
- Reuse the optional Last.fm connection for artist biographies, album
  descriptions, tags, and related context where its API provides them.
- Start lyrics support with a provider that offers an official display API,
  such as LRCLIB for plain and synchronized lyrics. Do not scrape lyrics sites.
- Keep commercial or restricted providers behind the same interfaces and only
  enable them when their display, caching, and attribution terms are satisfied.

Matching should prefer stable external IDs. Name-based matching should use
normalized artist, album, and track names plus track duration, retain the
provider and confidence, and return an unavailable or ambiguous result instead
of silently attaching uncertain information.

Add an enrichment cache with provider, entity type, lookup key, normalized
payload, source identifier/URL, match confidence, fetched/expiry timestamps,
and failure/backoff state. Cache durations and stored payloads must follow each
provider's terms. Every external source must use this cache; direct uncached
requests from user-facing views are not allowed. Negative results should be
cached briefly, concurrent lookups should be deduplicated, and rate-limited
requests should run through queued jobs with provider-specific throttling.
Expired cached data may be displayed immediately while a background refresh
runs when the provider's terms permit it.

Playback startup, seeking, queue progression, and navigation must never wait
for enrichment. The player should start from local catalog data and request
optional information independently. A slow, rate-limited, offline, or failing
provider may only affect its own Info or Lyrics state.

External enrichment is disabled by default because requests disclose current
listening information. Settings should provide separate controls for music
information and lyrics, provider credentials where required, and cache
clearing. Every displayed result must identify and link to its source.

## Implementation Phases

### Current Status

Completed:

- Laravel/API Platform backend and PostgreSQL development environment
- Database schema, relationships, browse/search indexes, and read-only catalog APIs
- Incremental filesystem scanner with queued execution and error isolation
- getID3 metadata extraction and normalized artist, album, track, and genre records
- Folder-cover discovery, embedded-artwork fallback, artwork caching, and thumbnail generation
- Vue/Vuetify application shell with responsive navigation, Pinia, routing, and English/German translations
- General settings for persisted language and color theme preferences, with a compact header and a unified playback-details panel
- Library-root list, create, edit, and remove workflow with canonical path, subfolder checks, and safe relative cover-path validation
- Scan start/cancel API, queued dispatch, progress/history API, and periodically refreshed Settings UI controls
- Structured scan diagnostics for invalid layouts, unreadable entries, malformed files, missing files, and unavailable roots
- Stale-file removal after scans, scoped preservation beneath paths that could not be read, and orphan cleanup for albums, artists, and genres
- Read-only Explorer-style server folder browser for selecting library roots
- Batched scan progress, cancellation checks, incremental fingerprints, and repeated metadata lookup caches
- Raw tag metadata sanitation for binary ID3 payloads that PostgreSQL JSONB cannot represent
- FFprobe fallback for playable files with malformed optional ID3/APE data,
  including bounded diagnostics and numeric tag range validation
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
- Add-to-playlist actions from tracks, albums, queue entries, and the player
- Playlist detail pages with ordered track lists, play/queue actions, removal confirmations, and reorder controls
- Creating a new playlist from the current playback queue
- Playlist detail usability refinements: visible playlist positions,
  selection mode for bulk removal, clearer drag-and-drop insertion feedback,
  and compact playlist-folder grouping
- Playback robustness for fast playlist switching, seeking, stale media events,
  and page refresh restoration
- Web Audio API frequency visualization in the expanded player with a persisted
  on/off player setting
- Artist detail pages with cached artist context, album and track tabs, artist
  images where available, contextual back navigation, and albums sorted by
  release year
- Preserved list filter state for albums, tracks, and artists across route
  navigation and library-root switching
- Top and bottom pagination on catalog lists and artist-detail tabs with
  compact first/last page controls and result-top scrolling on page changes
- Tokenized album and track search so combined artist/title or artist/album
  searches work, plus aborting stale album and track search requests while
  typing
- Runtime guide and manual Windows lifecycle scripts for Docker PostgreSQL,
  Laravel, the supervised queue listener, Vite, health checks, logs, scanning,
  troubleshooting, and lightweight backup
- Setup and distribution plan for a future non-developer packaged runtime with
  one app URL, Docker-mounted music folders, first-run setup, health checks,
  and backup/restore workflow
- Initial packaged Docker Compose skeleton with built Vue assets behind nginx,
  Laravel API, queue worker, scheduler, PostgreSQL, persistent storage volumes,
  and one configurable music-folder mount
- Initial packaged Windows startup, shutdown, and status scripts with generated
  local secrets, migration execution, localhost binding by default, explicit
  LAN mode, and printed access URLs
- Guarded development-to-packaged database migration helper that copies the
  Laravel APP_KEY, restores a PostgreSQL dump, and remaps library-root paths to
  mounted container paths
- Laravel middleware that protects filesystem and scan-management APIs from LAN access unless an admin token is configured
- Exact trusted-host validation, deny-by-default CORS configuration, and a frontend Security tab for verified session or device admin tokens
- Database-backed play events and track play statistics with a counted-play threshold, history views, aggregate album/artist statistics, and a never-played track filter
- Optional MP3 statistics synchronization using foo_playcount-compatible tags, with queued coalesced write-back
- Previewed and queued MP3 track/album metadata editing with verification, conflict fingerprints, and optional durable backups
- Last.fm authorization and asynchronous scrobbling with encrypted credentials, shared counted-play rules, retry handling, and delivery state
- Opt-in current-track enrichment using attributed Last.fm artist/album context and LRCLIB lyrics
- Provider-aware enrichment caching with atomic request deduplication, unique stale refresh jobs, configurable throttling, exponential backoff, diagnostics, and cache controls

In progress or still required for the first milestone:

- Broader end-to-end and packaging coverage
- Packaged local runtime that hides Laravel, Vite, PostgreSQL, and queue-worker
  details behind a straightforward startup flow

The implementation order changed from the original phase list. The scanner and artwork pipeline were completed before the catalog frontend, and playlists/favorites were brought forward because they build naturally on the playback queue. Local operation and LAN startup are now repeatable; current feature work focuses on reliable online enrichment and conservative external identity matching without coupling playback to provider availability.

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
- Generate and cache thumbnails for all artwork; serve folder originals from
  their guarded library paths and extract embedded originals from audio files
  only when requested.
- Fall back to embedded artwork when no configured folder image is available.
- Deduplicate cached artwork using checksums.
- Detect unchanged files using size and modification time.
- Update modified files and remove catalog records for files no longer discovered, while preserving records only beneath unreadable paths.
- Record malformed files and nonfatal warnings without stopping a scan.
- Execute scans through queued jobs.

### 4. Library Frontend

- Create the application shell and navigation.
- Build dashboard, artist, album, genre, and track views.
- Add global search, filters, sorting, and pagination.
- Add explicit user-selectable sorting controls to album and track lists. Keep
  sensible defaults, but allow sorting by common criteria such as artist,
  title, release year, play count, last played, and date added where data is
  available.
- Add responsive artwork grids and tabular track views.
- Display the generated cover thumbnail for every album in album lists and grids.
- Display the larger cached cover on album detail pages.
- Add album detail pages with album artwork overlay, album-level genre chips, play-album action, and highlighted current track.
- Add artist and genre action icons that open filtered album and track lists.
- Add clickable artist and album metadata in track lists.
- Provide useful placeholders for missing artwork and metadata.
- Add English and German translations from the beginning.

### 4a. Session Library Scope And Personal Album Information

- Add a global library-root selector with an explicit "All roots" option. (Complete)
- Keep the selected root in Pinia backed by session storage so it survives page
  refreshes but remains specific to the current browser session. (Complete)
- Apply the selected root to dashboard metrics, artists, albums, tracks, genres,
  search, list counts, filters, random selections, history, favorites, and
  playlist contents. Settings and scan administration remain unscoped. (Complete)
- Resolve root membership through `tracks -> media_files -> library_roots` and
  use distinct catalog entities so albums and artists shared by several roots
  are not duplicated in results or counts. (Complete for the current schema,
  where each album belongs to one physical root)
- Scope album details to tracks available in the selected root. Keep an album
  visible only when at least one matching track remains. (Complete)
- Do not stop current playback or discard the existing queue when the scope
  changes. Apply the new scope to subsequent browsing and newly generated play
  or queue actions. (Complete)
- Ensure all relevant backend collection, aggregate, search, and random-item
  endpoints accept the same nullable library-root filter. `null` means all
  enabled roots. (Complete; playlist reordering intentionally remains available
  only in the all-roots view)
- Add album-detail editing for purchase source, purchase date,
  physical-copy state, physical format, and optional personal notes without
  writing these values to audio tags. (Complete)
- Add physical-copy filters to album and track lists. Track filtering is based
  on the personal information of its album. (Complete)
- Show compact personal-information and physical-copy indicators on album
  details and useful list contexts without crowding narrow layouts. (Complete)
- Test cross-root entities, scoped counts, root switching, session restoration,
  personal-data preservation across scans, and scoped favorite/playlist views.

### 5. Audio Playback

- Implement an audio streaming endpoint with HTTP range support. (Complete)
- Validate file access against enabled library roots. (Complete)
- Add persistent player controls. (Complete)
- Add playback queue management using Pinia. (Complete)
- Show and manage the visible current queue. (Complete)
- Add an optional local music visualizer driven by the browser Web Audio API,
  without adding a Processing/p5-style dependency. (Complete)
- Add "queue album" and "queue track" actions beside "play now" actions. (Complete)
- Add random album and random track actions. (Complete)
- Add continuous play and random continuation settings. (Complete)
- Continue by album when album playback reaches the end, and switch visible album detail pages accordingly. (Complete)
- Continue by track when track-list playback reaches the end. (Complete)
- Make player metadata navigable: track title opens track detail, album opens album detail, artist opens filtered albums, and "Now playing" jumps to the current track. (Complete)
- Handle unavailable files and unsupported browser codecs clearly. (Basic error feedback complete; detailed codec guidance pending)
- Decide and implement the track-title navigation model for track-centric playback. (Complete)
- Consider FFmpeg-based transcoding only after the MVP.

### 5a. Playlists and Favorites

This phase was pulled forward after the queue model became stable. It builds on the queue rather than replacing it.

- Add favorite buttons to track detail, album detail, track lists, album lists, and player affordances. (Complete)
- Add favorite track and favorite album browse sections. (Complete)
- Add a playlist navigation section. (Complete)
- Add playlist folders for organizing custom playlists. (Foundation complete)
- Add playlist create, rename, move-to-folder, delete, and reorder workflows. (Complete)
- Add playlist create and delete workflows. (Foundation complete)
- Add ordered playlist item API for adding, removing, and reordering tracks. (Complete)
- Add "add to playlist" actions from tracks, albums, queue entries, and the player. (Complete)
- Allow creating a playlist from the current queue. (Complete)
- Add explicit sorting controls to the playlists overview, at minimum by
  folder/name, recently updated, and track count.
- Show playlist membership for tracks that already belong to one or more
  playlists, and provide an action from track contexts to navigate directly to
  one of those playlists.
- Consider importing/exporting playlists only after the core local workflow is stable.

### 5b. Metadata Editing

This phase has started with a preservation-oriented MP3 track-editing slice.
File writes remain queued and require a preview and explicit confirmation.

- Choose and wrap a tag-writing library behind a small backend adapter.
  (Complete for the shared byte-preserving MP3 ID3v2 editor)
- Define editable field mappings for MP3/ID3. (Complete for MP3 title, track
  artist, composer, performer, comment, track/disc number, year, album title,
  album artist, release year, total discs, and genres)
- Add track edit forms for per-track fields such as title, track number, disc
  number, artist, and genre. (Complete for MP3 title, track artist, composer,
  performer, comment, track number, disc number, year, and genres on track
  detail)
- Add album edit forms for shared fields such as album title, album artist,
  release year/date, and album genres. (Complete for MP3 album title, album
  artist, release year, total discs, and shared genres)
- Add album-track selection with bulk playback, queue, playlist, favorite, and
  metadata actions. The metadata mask must show common or mixed current values
  and leave every field untouched unless it is explicitly enabled. (Complete)
- Add selected-track metadata batches for track artist, composer, performer,
  comment, track/disc number, year, and genres with per-file minimization,
  preview, backup, verification, progress, and partial failures. (Complete)
- Support bulk album edits that can update all tracks in an album while allowing
  per-track exceptions for title, track number, and disc number. (Complete for
  sequential MP3 batches; track-specific fields are preserved)
- Add a preview/confirmation step that shows every affected file and tag field
  before writing. (Complete for individual MP3 tracks and album batches)
- Add optional file backup before write operations. (Complete for track and
  album MP3 edits with configurable retention and explicit restore tooling)
- Add queued tag-write jobs with progress, errors, and rollback guidance.
  (Queued job status, per-file progress, and partial-error reporting complete
  for individual tracks and album batches; durable backup/rollback guidance
  pending)
- Re-scan or re-read changed files after writing so the database reflects the
  actual file contents. (Complete for MP3 track and album edits)
- Add tests with small representative files for the supported audio/tag formats.
  (MP3 preservation and queued workflow fixtures complete)
- Optimize padded MP3 tag updates without copying the audio payload, while
  retaining recovery, verification, rollback, and full-copy fallback behavior.
  (Complete)

### 5c. Listening History And Scrobbling

Database listening history should be active before optional tag export or
Last.fm integration.

- Add play-event and play-statistics tables. (Complete)
- Define a "counted play" rule, for example after a minimum duration or playback
  percentage, so short previews do not inflate statistics. (Complete using the
  Last.fm rule: tracks must exceed 30 seconds and count after half their duration
  or four minutes, whichever comes first)
- Record app plays from the player when the counted-play threshold is reached.
  (Complete)
- Display play count, first played, and last played on track detail pages and in
  useful list contexts. (Complete for track details, track lists, album details,
  and artist lists)
- Add a never-played filter to the track list. (Complete)
- Add album/artist aggregate listening stats derived from track stats. (Complete)
- Add scanner import support for known playcount tags, including foobar2000 /
  foo_playcount-compatible fields where available. (Complete for aggregate
  count, first played, and last played fields)
- Add settings for listening-stat tag import and tag export. (Complete as one
  disabled-by-default synchronization setting)
- Add optional queued export of play count, first played, and last played back
  to file tags for interoperability with other players. (Complete for MP3)
- Add conflict handling when DB statistics and file-tag statistics disagree.
  (Non-destructive merge and source preservation complete; interactive conflict
  review remains pending)
- Add Last.fm settings, authentication/token storage, and explicit connect /
  disconnect workflow. (Complete with encrypted secrets and session state)
- Add Last.fm scrobbling for eligible app plays. (Complete with queued delivery,
  retry handling, and per-play delivery state)
- Consider Last.fm history import only after local statistics and scrobbling are
  stable.

### 5d. Online Artist, Album, And Lyrics Enrichment

- Define backend provider contracts and normalized DTOs for artist information,
  album information, and lyrics. (Foundation complete.)
- Add disabled-by-default settings for online music information and lyrics,
  including provider credentials and connection tests where required. (Opt-in
  information and lyrics switches complete; Last.fm credentials are reused for
  read-only context, LRCLIB requires none, and explicit provider checks are
  available in Settings.)
- Add a provider-aware PostgreSQL cache, request deduplication, expiry,
  negative caching, stale-while-revalidate behavior where permitted,
  retry/backoff behavior, and rate-limit enforcement. Route every provider
  through it. (Complete with atomic miss locks, unique queued stale refreshes,
  exponential failure backoff, configurable provider request limits, cache
  statistics, and confirmation-protected cache clearing.)
- Prefer MusicBrainz identifiers from scanned tags; otherwise use conservative
  name matching with explicit confidence and ambiguity handling. (Complete for
  artist and album identity: retained Picard IDs are authoritative, exact
  normalized search is the fallback, and uncertain candidates remain
  unmatched.)
- Expose local read endpoints for the current artist, album, and track lyrics;
  never expose provider credentials to Vue. (Track-scoped information,
  MusicBrainz identity, and lyrics endpoints complete.)
- Add an Info/Lyrics area to the player that handles loading, unavailable,
  ambiguous, stale-cache, and provider-error states without interrupting audio.
  (Complete for Last.fm artist/album context, MusicBrainz identity, and plain
  or synchronized LRCLIB lyrics.)
- Dispatch enrichment independently after playback starts; never place an
  external request on the playback, seeking, or queue-progression path.
- First support cached artist/album context and plain lyrics with source
  attribution. Add synchronized lyric scrolling only after the plain-lyrics
  workflow is stable. (Complete: synchronized LRCLIB lyrics now highlight and
  auto-center against the local playback clock, support line seeking, and fall
  back to plain lyrics when timestamps are unavailable.)
- Add fake-provider tests for matching, caching, disabled settings, throttling,
  provider failure, and prevention of outbound requests when enrichment is off.
  (Complete for Last.fm, MusicBrainz, and LRCLIB, including tagged and searched
  identity matches, ambiguity, lock contention, and stale refresh behavior.)
- Extend cached enrichment to detail pages after the current-playing workflow
  is stable. (Complete for album details with separate album/artist tabs and
  for dedicated artist details; multiple-provider fallback remains later.)

### 6. Settings and Scan Management

- Add and remove library roots. (Complete)
- Provide manual path input. (Complete)
- Provide a restricted server-side folder browser. (Complete)
- Configure ordered album-cover paths relative to album folders for each library root. (Complete)
- Validate cover paths as relative paths and confine their resolved files to the library root. (Complete)
- Select and prune excluded subfolders for each library root. (Complete)
- Show an example resolved cover location. (Pending)
- Start, cancel, and repeat scans. (Complete)
- Display live or periodically refreshed scan progress. (Complete)
- Show last-scan information and actionable file errors. (Complete)

### 7. Local and LAN Security

- Bind services to `127.0.0.1` by default. (Complete for current dev startup)
- Require an explicit configuration change for LAN settings access. (Complete with backend middleware and verified frontend token storage)
- Prevent path traversal and symbolic-link escapes. (Complete for streaming/scanning guard paths)
- Reject absolute album-cover paths and any parent-relative path that resolves outside the library root. (Complete)
- Restrict folder browsing to configured drives or parent directories. (Basic server-side browser complete; policy can be tightened before LAN exposure)
- Configure CORS and trusted hosts narrowly. (Complete; both use exact environment-driven allowlists)
- Before LAN settings access is enabled, add a shared administrative token or restrict settings operations to localhost. (Complete)
- Add explicit `start.ps1 -Lan` address selection, proxy-aware client IP
  protection, runtime status, and restricted Windows Firewall guidance.
  (Complete)

### 8. Testing and Packaging

- Unit-test path validation, metadata mapping, and incremental scan decisions. (Partially complete)
- Test folder-based cover discovery, embedded-artwork fallback, and thumbnail generation. (Complete at feature-test level)
- Feature-test API filters, scan operations, favorites, playlists, dashboard metrics, artwork, and range streaming. (Complete)
- Use small MP3, FLAC, Ogg, and malformed-file fixtures. (Partially complete)
- Add frontend store and component tests. (Store tests complete for catalog, roots, scans, preferences, player, favorites, and playlists)
- Add end-to-end coverage for configuration, scanning, browsing, and playback.
- Keep the current developer runtime documented separately from the planned
  packaged runtime. (Development runtime complete; packaged runtime plan
  documented in `docs/setup-and-distribution.md`)
- Add a production Compose architecture with one user-facing HTTP port, built
  frontend assets, backend API, queue worker, scheduler, PostgreSQL, persistent
  storage, and configurable music-folder mounts. (Initial skeleton complete)
- Add packaged Windows startup, shutdown, and status scripts that check Docker,
  generate missing secrets once, run migrations, print the app URL, and keep LAN
  mode explicit. (Initial complete)
- Add a guarded migration helper for moving the current development database to
  packaged mode, including APP_KEY preservation and library-root path remapping.
  (Initial complete)
- Add a first-run setup UI for runtime checks, mounted library folders, cover
  paths, scan exclusions, metadata-writing settings, optional connections, and
  the first scan. (Complete, with persisted resumable progress)
- Add Settings > System health checks for database, queue worker, scheduler,
  storage, mounted roots, stale scans, and failed queue jobs. (Complete for
  database and queue state, scheduler heartbeat, storage, mounted roots, active
  and failed scans, and failed queue jobs)
- Add manual backup and restore commands for PostgreSQL data and application
  storage. (Complete with checksummed development and packaged bundles,
  APP_KEY preservation, safety backups, and Settings status)

## Recommended Next Step

The durable metadata backup policy is complete: it is disabled by default,
uses a configurable location and retention period, preserves source-relative
paths in unique copies, records checksums and edit ownership, exposes recovery
details, and provides cleanup and path-checked restore commands.

The planned MP3 metadata field set is complete. Album track selection now adds
bulk playback, queue, playlist, favorite, and metadata actions; selected-track
metadata edits show common and mixed values and only write explicitly enabled
fields. A browsable backup audit in Settings remains as a later workflow
refinement.

The first Last.fm connector milestone is complete: account authorization,
encrypted local credentials, opt-in scrobbling, shared local/Last.fm eligibility
rules, and asynchronous delivery are implemented. The next connector refinement
is operational visibility for failed or ignored scrobbles before considering
history import or now-playing updates.

The first online-enrichment milestone and its reliability layer are complete:
provider-neutral services, opt-in settings, attributed Last.fm and LRCLIB
content, atomic request deduplication, stale background refresh, configurable
request limits, failure backoff, provider checks, and cache management are in
place without coupling playback to provider availability.

The MusicBrainz identity milestone and synchronized-lyrics workflow are
complete for the current-playing workflow. It reads retained Picard identifiers
without requiring a rescan,
uses conservative exact-name fallback searches, exposes confidence and
ambiguity explicitly, respects MusicBrainz request pacing, and displays compact
structured identity alongside Last.fm context. Timestamped LRCLIB lyrics now
follow playback and allow direct seeking while plain lyrics remain the fallback.
Album details now reuse the cached MusicBrainz and Last.fm results in a tabbed,
attributed panel. Artist names open a dedicated page with summary statistics,
paginated album and track tabs, playback actions, and cached artist context.
Optional artist portraits are resolved from MusicBrainz IDs through Wikidata,
downloaded from Wikimedia Commons through a host-restricted Laravel proxy,
attributed, validated, cached privately, and shown with a local fallback.
Additional provider fallback remains a later refinement.

The player now includes an optional Web Audio API visualizer in the expanded
footer. It is local-only, dependency-free, persisted in player preferences, and
uses logarithmic frequency bands so musical activity is distributed more
naturally across the bars. Further visual styles can be added later, but the
foundation is complete.

The session-wide library-root scope is complete. The app keeps the selection in
session storage, applies it consistently to catalog data, metrics, generated
playback choices, favorites, history, and playlist contents, and preserves the
existing player queue when the selection changes. Personal album information is
now stored independently from scanned metadata and supports purchase source,
purchase date, physical-copy state, physical format, and notes, plus
physical-copy filters in album and track lists. The next catalog refinements are
explicit sorting controls for albums/tracks/playlists and track-to-playlist
membership navigation.

The LAN authorization boundary, browser token workflow, trusted-host checks,
CORS allowlist, explicit startup mode, proxy-aware client IP handling, Windows
Firewall guidance, packaged Compose runtime, startup scripts, resumable
first-run setup, health checks, and checksummed manual backup and guarded
restore for PostgreSQL and application storage are complete. The initial
versioned Windows portable bundle, double-click launcher, host-folder picker,
installation guide, and release checksum are also complete. Remaining release
work is performing the first live tagged GitHub Release and verifying LAN
behavior from a second physical device on the local network. Repeatable release
builds and GitHub publication are now automated by a tag-driven workflow that
runs backend and frontend validation before building and auditing the Windows
portable archive. Packaged multi-root mount management uses a generated Compose
override with stable `/music/root-N` mappings and a native Windows folder-selection
flow.

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
- Treat tag editing as a file-writing workflow, not just a database update.
- Keep app listening statistics in the database as the source of truth; importing or exporting statistics through file tags is optional and disabled by default.
- Treat the selected library root as session-level query context, with all roots
  as the default, rather than modifying or duplicating catalog records.
- Keep personal album information separate from scanned metadata so filesystem
  scans and tag edits cannot overwrite it.

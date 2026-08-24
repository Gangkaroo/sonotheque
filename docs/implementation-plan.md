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
- Versioned portable Windows releases using Docker Compose, nginx, Laravel,
  queue and scheduler workers, PostgreSQL, and explicit host-folder mounts

## Repository Structure

```text
sonotheque/
|-- backend/          Laravel and API Platform
|-- frontend/         Vue application
|-- docs/             Architecture and project documentation
`-- compose.yaml
```

## Product Scope

The implemented release baseline and active roadmap include:

- Configuration of one or more local music folders
- Manual and incremental library scans
- Optional per-root filesystem monitoring with targeted scans, periodic full
  reconciliation, and a consolidated cross-root activity log
- Removal of stale catalog records after scans, including deleted and newly
  excluded files, while preserving records beneath unreadable paths
- Identity-preserving reconciliation of unambiguous externally moved files by
  tag-independent audio-content fingerprints
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
- Continuous album and track playback with optional random next-item selection;
  random playback started from filtered album or track lists keeps a frozen
  root/search/genre/year/ownership/sort scope for subsequent choices
- Visible current playback queue with album and track queue actions
- Favorite tracks and favorite albums with browse sections
- Personal half-star ratings from 0.5 to 5 stars for albums and tracks
- Custom playlists, playlist folders, ordered playlist items, queue-to-playlist
  actions, M3U/M3U8 import and export, and optional background file
  synchronization
- Personal album information, including purchase source, purchase date,
  physical-copy state, physical format, and sanitized rich-text notes
- Optional Discogs connection for matching albums to exact owned physical
  releases and collection instances
- Root-scoped folder browsing with file and folder playback, queue, playlist,
  subtree-rescan, and guarded same-parent rename actions
- Database-backed listening history and statistics with optional MP3 tag
  synchronization
- Optional Last.fm scrobbling with asynchronous retries and a filterable
  delivery log
- Optional cached artist, album, identity, portrait, and lyrics enrichment
  through Last.fm, MusicBrainz, Wikidata, Wikimedia Commons, and LRCLIB
- Disabled-by-default local audio analysis and musical similarity
  recommendations using versioned pretrained models, reusable background
  analysis, pgvector HNSW neighbour search, a persisted CPU/CUDA method
  selector, and optional transparent tempo/key/intensity refinement
- Independently disabled-by-default local collection assistant with a
  root-aware conversational view, local history, linked catalog references,
  safe Markdown rendering, and validated read-only Sonotheque tools for
  collection totals, catalog search, listening history, rankings, unplayed
  albums, and root-scoped audio-similarity recommendations
- English and German translations
- Localhost access by default and optional LAN access

The following longer-term features remain deferred:

- User accounts and permissions
- Audio transcoding
- Last.fm history import and now-playing updates
- Mobile applications

## Architecture

The Vue single-page application communicates with the Laravel application through an API Platform API. Laravel owns library configuration, scanning, metadata normalization, search, artwork delivery, and audio streaming.

Scanning is performed asynchronously using Laravel queue jobs. Each discovered
file is inspected with getID3 and normalized into relational library records.
Short interactive jobs, library scans, and audio-intelligence work use separate
database queues and workers so a long scan cannot block metadata edits,
scrobbles, playlist synchronization, or other bounded operations.
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

### Folder Browsing And Subtree Scans

The catalog provides a root-scoped folder view without creating a second,
duplicated folder hierarchy in PostgreSQL. Navigation lazily lists only
the immediate children of the current directory from the filesystem, then join
supported files to `media_files` and `tracks` for playable metadata and actions.
Empty or not-yet-indexed directories therefore remain visible, while playback
continues to use catalog track IDs and the existing guarded streaming endpoint.

The browser sends a library-root ID and normalized relative directory path,
never an arbitrary absolute host path. Laravel resolves every directory within
the enabled root, rejects traversal and symbolic-link escapes, and applies the
root's configured exclusions. An "All roots" catalog scope must prompt for a
specific root before folder navigation begins.

Single-file actions operate on one indexed, playable track. Folder actions use
all supported, indexed tracks in the selected directory and its descendants so
multi-disc and nested layouts behave as one collection. Large folder actions
show the number of affected tracks and retain deterministic filesystem,
disc, and track ordering. Existing player queue and add-to-playlist workflows
are reused rather than introducing another queue representation.

Subtree scans extend a normal scan run with an optional relative directory
scope. Discovery, progress, diagnostics, cancellation, incremental fingerprint
checks, and stale-file cleanup must all remain inside that subtree. Records
beneath unreadable paths are preserved as in full scans, and records elsewhere
in the root must never be marked stale. A full-root and subtree scan may not
overlap for the same root initially. Subtree rescans remain administrative
operations protected by the existing localhost/LAN admin-token boundary.

The folder view permits guarded same-parent renames. It rejects overwrites,
symbolic links, excluded paths, extension changes, unsafe names, read-only
parents, and operations during an active scan. A successful rename updates the
existing catalog paths without replacing media-file or track identities. Move,
delete, folder creation, and other filesystem mutations remain deferred until
their conflict handling, permissions, rollback, and catalog reconciliation have
explicit designs.

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
- Optional normalized relative subtree path; `null` means the complete root
- Warning and error summaries

### `media_files`

Physical file information:

- Library root and relative path
- Canonical path or path fingerprint
- File size and modification time
- MIME type, container, codec, bitrate, and sample rate
- Scan state and last-seen timestamp
- Versioned SHA-256 audio-content fingerprint used for unambiguous move detection
- Raw metadata in JSONB

### Library entities

- `tracks`
- `artists`
- `albums`
- `genres`
- Track-artist and track-genre pivot tables

Tracks contain normalized values such as title, duration, year, track number, disc number, and links to the source media file.

Albums and tracks also carry an optional personal rating stored as integer
half-steps from 1 through 10. The API presents these values as 0.5 through 5
stars. A missing value means unrated and remains distinct from the lowest rating.

Artists store a normalized sort name and an indexed browse initial. The browse initial contains `A` through `Z`; accented Latin initials are transliterated into those buckets, while names beginning with numbers, symbols, or other characters are grouped under `#`. Albums store the original release year separately from track-level tag data. PostgreSQL trigram indexes support case-insensitive partial searches for artist, album, and genre names. Genre names are unique without regard to letter case so filter values do not fragment into variants such as `Rock` and `rock`.

### `artwork`

Cached artwork metadata:

- Source type and original source path
- Cache path for generated or extracted assets when needed
- Thumbnail cache path
- MIME type
- Width and height when available
- Checksum for deduplication

Artwork should be cached as files rather than stored as PostgreSQL binary data.
Folder artwork remains in its guarded library location instead of being copied
at full resolution into application storage. Album details serve that source
image through a guarded endpoint, while embedded artwork may be extracted and
cached when needed. Generated thumbnails are cached for album lists and grids.
Thumbnail dimensions and image quality should be application-level
configuration with sensible defaults.

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

- `album_personal_metadata`: one optional row per album for album-wide personal
  notes and timestamps. Notes are stored as sanitized HTML from a deliberately
  small rich-text editor and rendered through a second client-side sanitizer.
- Purchase and edition-specific information belongs to `owned_album_copies` so
  an album can represent digital ownership, several physical editions, or more
  than one copy without duplicating scanned catalog metadata.
- Keep purchase source as free text initially; do not create duplicate scanned
  album or artist values for personal information.
- Preserve personal information when a scan updates an album. Apply the same
  orphan/relinking policy used for favorites if an album temporarily disappears
  or is rediscovered.

### Owned Album Copies And Discogs

An album may correspond to more than one physical edition or owned copy. Exact
release ownership therefore must not be represented by a single Discogs field
on the scanned album or by overwriting scanned metadata.

- `owned_album_copies`: one-to-many owned copies for an album, including format,
  purchase source, purchase date, optional purchase price, media and sleeve
  condition, personal notes, and timestamps.
- Each copy may store a provider plus stable external identifiers. For Discogs,
  retain the exact release ID and, when the copy is present in the connected
  user's collection, its collection instance ID and folder ID. A master-release
  ID alone is insufficient because it does not identify a particular pressing.
- Keep album-wide personal notes separate from copy-specific ownership data.
  Migrate existing physical-copy, format, and purchase values into one owned
  copy without losing current personal information.
- Store durable provider identifiers locally, but treat descriptive Discogs
  payloads as provider data subject to its current caching, attribution, and
  display terms. Do not make catalog browsing or playback depend on Discogs.

The first integration is read-only. A locally configured Discogs personal
access token identifies the collection owner; the provider boundary should
permit a future OAuth connection without changing the ownership schema. Album
matching searches the user's collection and the Discogs release database using
artist, title, barcode, catalog number, year, country, and format. Candidate
results show enough edition information for an explicit user decision. Never
attach a release automatically from artist and title alone.

Later write support may add a selected release to the connected Discogs
collection and synchronize copy-specific folder, condition, rating, or notes.
Those operations require separate confirmation and must not be part of the
initial matching milestone.

### Editable Metadata

Tag editing is implemented for ordinary MP3 ID3v2.3/ID3v2.4 files and
losslessly mappable ID3v2.2 files, which are converted to ID3v2.3. It uses
stronger safety guarantees than catalog browsing because it writes back to
music files.

The UI should distinguish album-level metadata from track-level metadata:

- Album-level fields: album title, album artist, original release year/date,
  total discs, album genres, shared comments, and shared release metadata.
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
Non-standard little-endian ID3 play counters are normalized, while ambiguous
global popularity fields are retained as source metadata but are not treated as
per-track play counts.
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

Musician credits are a structured extension of album enrichment. The user-facing
section is named **Musicians** and should describe people or credited groups that
performed on the matched release or its recordings, including instruments,
vocal roles, guest/additional attributes, credited-as names, and the tracks to
which each credit applies. Do not infer album participation from historical band
membership alone. Prefer an exact MusicBrainz release ID; a release-group match
is not sufficient to silently attach edition-specific personnel.

Retrieved credits must not remain only in the general enrichment cache. Store
normalized musician identities, provider identifiers, album-wide credits, and
track-specific credits in indexed PostgreSQL tables so a later milestone can
filter albums and tracks by musician. Keep the provider, source release, fetch
time, and credit scope, and retain credited names separately from normalized
identity. Cross-provider records must not be merged by name alone.

Collection building is lazy by default. Opening an album may enqueue a bounded,
rate-limited MusicBrainz personnel lookup when that album has not been checked;
existing album data and playback remain immediately available. Persist positive,
negative, ambiguous, and failed outcomes so repeat visits do not repeat work.
Expose how many albums have musician data, because filters over a partially
enriched collection must not imply that unchecked albums have no matching
musician. The optional, manually started backfill is root-scoped, pausable,
resumable, progress-aware, and skips albums whose current musician lookup
version is already complete.

Centralize outcomes that require a user decision in a dedicated, root-scoped
**Musician Review** page linked from the Musicians section and the backfill
summary. It should paginate ambiguous matches and failed lookups separately,
show the local album alongside compact MusicBrainz release candidates, and
reuse the existing exact-release selection workflow. Users should also be able
to retry transient failures, open the album's musician editor, or explicitly
mark an item as reviewed without a suitable provider match. Persist review
decisions independently from imported credits so backfills do not repeatedly
surface dismissed cases; a newer lookup version may make them reviewable again.

MusicBrainz musician retrieval requires a new cache/payload schema version.
Previously cached album identity responses did not request recording-level
relationships and therefore cannot be interpreted as complete musician data.
Treat older positive and negative MusicBrainz album cache entries as missing for
this lookup and refetch them once, lazily, under the new versioned lookup key.
Do not require users to clear all enrichment caches, and do not repeatedly
refetch entries that have already completed the new musician-credit version.

Playback startup, seeking, queue progression, and navigation must never wait
for enrichment. The player should start from local catalog data and request
optional information independently. A slow, rate-limited, offline, or failing
provider may only affect its own Info or Lyrics state.

External enrichment is disabled by default because requests disclose current
listening information. Settings should provide separate controls for music
information and lyrics, provider credentials where required, and cache
clearing. Every displayed result must identify and link to its source.

### Local Audio Intelligence And Collection Assistant

Local AI capabilities are divided into two independently optional features.
Neither feature may run on the playback, seeking, queue-progression, or ordinary
scan critical path.

Both features are disabled by default. A normal Sonotheque installation must not
download models, start analyzers, reserve GPU memory, run background analysis,
or start an LLM runtime. The pgvector extension may be present in the standard
PostgreSQL image, but it remains idle until analysis artifacts are indexed.
Enabling audio analysis does not enable the collection assistant, and enabling
the assistant must not implicitly start full-library audio analysis.

The local audio-intelligence feature analyzes audio asynchronously and stores
versioned results independently from scanned tags. A dedicated Python worker
keeps the machine-learning runtime outside PHP. The implemented analyzer uses
Essentia for conventional features and the Discogs multi-similarity EffNet model
for track-to-track embeddings. The model remains replaceable and is recorded
with its version, dimensions, checksum, and license. Audio-to-text semantic
search is not part of the current feature.

Analysis is keyed by the existing tag-independent audio-content fingerprint.
Renames, moves, and ID3/APEv2 edits therefore reuse results, while changed audio
queues a new analysis. PostgreSQL remains the source of truth; pgvector is the
preferred optional extension for cosine similarity and HNSW indexing. Keep
model registrations, scalar audio features, embeddings, job state, confidence,
and errors outside the core `tracks` table so models can be upgraded or compared
without destructive schema changes.

Similarity starts as a deterministic backend service rather than an LLM task.
It retrieves audio neighbors and reranks them using configurable BPM, key,
energy, mood, library-root scope, availability, artist repetition, and duplicate
penalties. The UI should offer a queue preview and explanation before applying
recommendations. Initial controls belong in track actions, track details, and
the existing playback-information area rather than adding another permanent
player icon.

The local collection assistant is a later consumer of trusted Sonotheque APIs.
A local runtime such as Ollama may choose from narrowly defined tools for catalog
search, collection aggregates, listening statistics, similarity search, and
queue-preview generation. Laravel validates every structured request, applies
normal authorization and library-root scope, limits result sizes, and returns
linked evidence. The model never receives database credentials, arbitrary SQL,
filesystem access, or permission to mutate playlists or playback without an
explicit user confirmation.

No model should be trained from scratch for the initial release. The first-run
cost is analysis and indexing with pretrained models. Optional personalization
can later learn a lightweight reranking profile from explicit feedback, skips,
completed plays, favorites, and playlists without replacing the base embedding
model. A few ratings cannot retrain the embedding model itself. They can instead
adjust a small, local and reversible reranking layer. Feedback collection must
be opt-in and inspectable.

The ordinary Audio intelligence settings should expose only controls that help
the listener: independent enablement, collection coverage, current analysis
progress, pause/resume, the CPU/CUDA execution choice, and recommendation
preferences such as tempo/key/energy influence and library-root scope. If
matches are poor, the user can rate them and train a small, reversible local
reranker. The base embedding remains unchanged, and implicit behavioral signals
remain deferred until they have been evaluated separately.

Analyzer health, model identity and license, validation samples, bounded pool
expansion, feature distributions, and baseline-quality measurements are
experimental diagnostics. Keep them collapsed behind an Advanced diagnostics
section or a development setting; they are not required after the analyzer
baseline has been accepted and should never block normal collection analysis.
Model downloads and all analysis remain local by default. A packaged optional
AI profile must degrade cleanly when no supported GPU, sufficient memory, model,
or pgvector extension is available.

Disabling Audio Intelligence requests a pause or cancellation for active work
without deleting previously calculated results. CPU/GPU selection, resource
limits, and bounded preparation concurrency are implemented. Idle-only
scheduling and automatic resource-pressure pausing remain optional future
optimizations rather than current behavior.

## Implementation Phases

### Current Status

Completed:

- Laravel/API Platform backend and PostgreSQL development environment
- Database schema, relationships, browse/search indexes, and read-only catalog APIs
- Incremental filesystem scanner with queued execution, error isolation,
  disk-backed single-pass discovery manifests, and versioned playback-tag
  import checkpoints that avoid repeated work for unchanged files. Unchanged
  albums keep one manifest representative for artwork verification while their
  remaining files are updated through the batched fast path.
- Optional per-root portable filesystem monitoring with configurable checks,
  targeted subtree scans, periodic full reconciliation, disconnected-root
  preservation, serialized per-root scan dispatch, durable disabled-state
  handling, and no overlapping scans
- Admin-protected Trash view for reviewing unavailable tracks and permanently
  deleting selected catalog identities together with their personal references
- Persistent cross-root library activity log for watcher events, scan lifecycle
  entries, warnings, and errors, with root/scan links, filters, pagination, and
  bounded retention
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
- Database-backed album and track ratings in half-star increments, with album
  ratings on album details/cards and responsive track ratings on track details
  and playable track lists
- Playlist folder, playlist, and ordered playlist-item persistence APIs
- Playlists navigation page with folder and playlist creation/deletion
- Add-to-playlist actions from tracks, albums, queue entries, and the player
- Batched playlist-membership actions in track details, track lists, and album
  track lists, with direct navigation to the highlighted playlist item
- Playlist detail pages with ordered track lists, play/queue actions, removal confirmations, and reorder controls
- Creating a new playlist from the current playback queue
- Playlist detail usability refinements: visible playlist positions,
  selection mode for bulk removal, clearer drag-and-drop insertion feedback,
  and compact playlist-folder grouping
- M3U/M3U8 export for albums and custom playlists, reusable export locations,
  a dedicated playlist-settings tab, and optional asynchronous synchronization
  that mirrors playlist folders and reports live progress
- M3U/M3U8 import from a server-visible file picker with relative and absolute
  path resolution across enabled library roots, preserved order and duplicate
  entries, optional playlist-folder assignment, and complete unmatched-entry
  reporting
- Playback robustness for fast playlist switching, seeking, stale media events,
  and page refresh restoration
- Frozen album and track playback scopes that preserve the active root and
  list filters for continuous sequential or random playback and describe the
  captured scope in the queue
- Web Audio API frequency visualization in the expanded player with a persisted
  on/off player setting
- Artist detail pages with cached artist context, album and track tabs, artist
  images where available, contextual back navigation, and albums sorted by
  release year
- Preserved list filter state for albums, tracks, and artists across route
  navigation and library-root switching
- Bounded in-memory route caching for the dashboard and top-level browse views,
  including scroll restoration and a root/filter/page-aware catalog response
  cache. Catalog mutations and scan lifecycle changes invalidate cached data;
  settings and detail/edit pages remain uncached so protected and mutable state
  is loaded fresh.
- Top and bottom pagination on catalog lists and artist-detail tabs with
  compact first/last page controls and result-top scrolling on page changes
- Tokenized album and track search so combined artist/title or artist/album
  searches work, plus aborting stale album and track search requests while
  typing
- Persisted album, track, and playlist sorting controls for title or name,
  release year, playback activity, creation or update time, and track count
  where applicable
- Runtime guide and manual Windows lifecycle scripts for Docker PostgreSQL,
  Laravel, automatically restarted queue workers, Vite, health checks, logs, scanning,
  troubleshooting, and lightweight backup
- Setup and distribution documentation for the non-developer packaged runtime with
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
- Exact trusted-host validation, deny-by-default CORS configuration, a frontend
  Security tab for verified session or device admin tokens, and documented
  server-token recovery
- Database-backed play events and track play statistics with a counted-play threshold, history views, aggregate album/artist statistics, and a never-played track filter
- Shared listen-time accumulation for local statistics and Last.fm that counts
  actual advancing playback time, survives refreshes, and does not treat seeking
  to a late position as listening
- Optional MP3 statistics synchronization using foo_playcount-compatible tags, with queued coalesced write-back
- Defensive play-count import that normalizes non-standard ID3 counters and
  excludes ambiguous global popularity values from per-track totals
- Previewed and queued MP3 track/album metadata editing with verification, conflict fingerprints, and optional durable backups
- Track, album, and artist detail timestamps for when catalog records were added
  and last updated, plus track- and album-level cleanup of additional ID3v2
  frames. Playback-statistics frames are identified and protected while tag
  synchronization manages them.
- Last.fm authorization and asynchronous scrobbling with encrypted credentials, shared counted-play rules, retry handling, and delivery state
- Opt-in current-track enrichment using attributed Last.fm artist/album context and LRCLIB lyrics
- Provider-aware enrichment caching with atomic request deduplication, unique stale refresh jobs, configurable throttling, exponential backoff, diagnostics, and cache controls
- Root-scoped folder browsing with guarded relative paths, virtualized large
  listings, indexed-file and recursive folder actions, and subtree scan runs
  whose stale cleanup cannot affect records outside the selected directory
- Guarded file and folder renaming inside a library root, with filesystem
  rollback on catalog failure and preservation of track, playlist, favorite,
  queue, and listening-statistics identity
- Isolated packaged Playwright coverage for setup, scanning, folder navigation,
  large folder confirmation, subtree scan cancellation, range streaming,
  seeking, queue progression, track switching, and playback restoration
- Empty-install first-run coverage that configures a library root and ordered
  cover paths, persists optional metadata settings across refresh, leaves
  online providers disabled, scans the mounted fixture, and completes setup
- A real `v0.1.0`-to-current packaged upgrade test that preserves PostgreSQL,
  application storage, catalog state, favorites, playlists, personal metadata,
  settings, and mounted library paths while running all current migrations
- Physical-device LAN verification for catalog browsing and playback, anonymous
  administration denial, a narrowly scoped Windows Firewall rule, and an
  automated host preflight for valid and invalid admin-token behavior
- One-to-many owned album copies with lossless migration of existing purchase,
  format, and physical-copy data while album-wide notes remain separate
- Sanitized album rich-text notes with a compact Tiptap/Vuetify toolbar for
  emphasis, lists, quotes, and safe external links
- Encrypted Discogs personal-token connection with immediate account identity
  validation, disconnect support, LAN admin protection, and a Connections-tab UI
- Read-only matching of owned album copies to exact Discogs releases and
  collection instances, including duplicate-instance selection, cached release
  details and thumbnails, refresh, change, and unlink actions
- Disabled-by-default Audio intelligence settings with durable representative
  validation samples selected across enabled roots, artists, and genres; bounded,
  resumable fingerprint preparation; a versioned analyzer contract; and
  manually provisioned analysis execution. No analyzer or model download is
  started automatically
- Durable all-root or root-scoped collection analysis with separate collection,
  validation, and bounded pool-expansion run state; pause/resume checkpoints and
  content-addressed artifacts prevent completed audio from being analyzed twice
- Persistent CUDA analysis service with batched model inference and overlapped
  CPU preparation, plus a cancellable CPU/CUDA benchmark that records verified
  throughput and a hardware-specific recommendation without changing analysis
  artifacts or run progress
- Optional local Collection Assistant with explicit Ollama discovery and model
  verification, root-scoped browser-local conversations, bounded context,
  guarded catalog and listening-statistics tools, verified navigation
  references, safe Markdown display, direct low-latency collection totals, and
  configurable model residency and output limits. Track, album, artist, and
  genre rankings support aggregate all-time statistics and timestamped periods.
  A guarded similarity tool resolves one named reference track, reports
  ambiguity and analysis coverage, and returns existing pgvector results with
  embedding and refined scores without mutating playback state. Explicit
  play-now and add-to-queue requests return a verified preview whose playable
  payload stays outside model context and requires browser-side confirmation.

Open roadmap work:

- Cross-platform packaged distribution for Linux and macOS. The shared
  configuration core, POSIX lifecycle/root launcher, Linux host ownership and
  Ollama routing, TAR release artifact, compatible checksummed backup/restore,
  Audio Intelligence provisioning, native picker fallbacks, Ubuntu smoke
  checks, and an explicit support matrix are implemented. Practical macOS
  Docker Desktop and broader ARM64 validation remain release-hardening work.
- Browsable metadata-backup audit (deferred; the command-based recovery
  workflow is sufficient for now)
- Optional playback-statistics conflict review and detailed unsupported-codec
  guidance
- Optional import/export of personal ratings through explicitly configured,
  format-aware file-tag mappings; disabled by default, with database ratings as
  the source of truth
- Optional measured playlist-order refinements such as inspectable transition
  penalties or a Thorough mode, only if they improve accepted previews
- Optional remote access with trusted-browser enrollment, explicit local
  approval, revocation, and server-enforced read-only capabilities. Prefer a
  private overlay network; direct Internet exposure requires a production
  HTTPS gateway and remains disabled by default. This is the final deferred
  feature and starts only after all other roadmap work is complete

The implementation order changed from the original phase list. The scanner and artwork pipeline were completed before the catalog frontend, and playlists/favorites were brought forward because they build naturally on the playback queue. Local operation, physically verified LAN access, packaged first-run, folder and playback workflows, and the `v0.1.0` Windows portable release are repeatable. Cross-platform command and release foundations are complete; practical macOS/ARM64 validation remains release hardening, while the browsable metadata-backup audit remains intentionally deferred.

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
- Detect unchanged files using size and modification time. Keep one unchanged
  file per album in the processing manifest to verify artwork, and batch-update
  the seen state of the remaining unchanged files without reparsing or a second
  manifest pass. (Complete)
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
  available. (Complete)
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

### 4b. Root-Scoped Folder Browser And Subtree Scans

This phase adds filesystem-oriented navigation without turning the catalog UI
into a general-purpose file manager.

- Add a Folders navigation entry that uses the current session root when one is
  selected and requests a specific root when the scope is "All roots".
  (Complete)
- Reuse or extract the existing guarded folder-listing service so the page loads
  one directory level at a time instead of recursively walking a drive.
  (Complete with a dedicated catalog browser sharing the path resolver)
- Accept only a root ID and normalized relative path in folder APIs. Resolve the
  path within the enabled root and reject traversal, symbolic links, excluded
  directories, unavailable roots, and paths outside configured mounts.
  (Complete)
- Return immediate child directories plus supported files enriched with indexed
  track, artist, album, duration, availability, and artwork data where present.
  Hide unrelated non-audio files in the first version. (Complete)
- Add breadcrumb and parent navigation, clear loading/error/empty states, and
  responsive English and German layouts. (Complete; large directories use
  virtualized rendering)
- Add play-now, add-to-queue, and add-to-playlist actions for a single indexed
  track. (Complete)
- Add the same actions for all indexed tracks in the selected folder subtree.
  Show the affected track count, confirm unusually large actions, and use a
  deterministic relative-path/disc/track order. (Complete; actions affecting
  500 or more tracks use a count-only preflight before loading the full list)
- Reuse the Pinia player queue, playlist chooser, and existing playback payloads
  instead of creating folder-specific playback state. (Complete)
- Add an optional normalized subtree path to scan runs and dispatch. Keep one
  active scan per root so full and subtree scans cannot overlap initially.
  (Complete)
- Restrict discovery, progress, diagnostics, incremental updates, and stale-file
  removal to the selected subtree. Preserve unseen records beneath unreadable
  paths and leave every record outside the subtree untouched. (Complete)
- Expose subtree scan progress, cancellation, completion details, and scan
  history consistently from both the folder view and Settings. (Complete)
- Protect subtree rescans with the existing scan-management admin-token rules.
  (Complete)
- Add optional per-root filesystem monitoring without relying on
  platform-specific notification delivery. Persist compact per-directory
  signatures, group changes into the smallest safe subtree, retain pending work
  while another scan is active, and periodically run a full reconciliation.
  Keep it disabled by default and expose conservative polling controls for
  large HDD collections. (Complete)
- Add a consolidated activity log across all library roots. Record automatic
  monitoring events, scan lifecycle entries, and every scan warning/error;
  retain optional root and scan-run links, and expose server-side filtering,
  pagination, and bounded retention in Settings. (Complete)
- Add same-parent rename actions for visible files and folders. Reject unsafe
  names, extension changes, overwrites, symbolic links, excluded paths, and
  active scans; update descendant media-file and album paths transactionally
  while preserving track IDs. (Complete)
- Detect files moved outside Sonotheque by a stable content fingerprint and
  reconcile unambiguous old/new paths without replacing track IDs. Do not infer
  identity from file size, timestamps, or tags alone because duplicate releases
  are common. Fingerprint encoded audio payloads rather than mutable ID3/APEv2
  data, and decline ambiguous duplicate matches. New and changed files receive
  fingerprints during normal scans; missing legacy fingerprints are not
  backfilled inline because doing so makes routine scans prohibitively slow.
  (Complete for fingerprinted files)
- Run a one-time, restartable maintenance backfill for legacy media rows using
  the existing audio-content fingerprinter. Completed rows are the checkpoint,
  so restarting skips finished work. Do not add a permanent UI or normal-scan
  behavior for this migration-only task. (Complete for the current catalog;
  seven malformed files remain conservatively unfingerprinted)
- Add backend path/scope/cleanup tests, frontend navigation/action tests, and an
  end-to-end nested-disc fixture before enabling the feature in packaged mode.
  (Complete with PostgreSQL-backed API coverage, frontend store coverage, and
  an isolated packaged Playwright fixture)
- Replace common-ancestor watcher scans with a durable multi-path change set.
  Capture added/updated directories, missing path prefixes, and artwork impact
  paths separately; collapse overlapping entries without widening them to a
  whole letter folder or root. One queued delta run may process several
  disjoint paths while retaining the one-active-scan-per-root rule. Directory
  membership and direct-file signatures identify new folders independently of
  inherited folder timestamps. (Complete)
- Treat files absent from a trustworthy delta or reconciliation as unavailable
  instead of deleting their media and track identity immediately. Set
  `media_files.status` to `missing`, hide those tracks from ordinary catalog
  browsing and random playback, and keep playlist items, favorites, listening
  statistics, and other personal references intact. Playlist rows remain
  visible but disabled and clearly marked as unavailable. (Complete)
- Add a Trash view for unavailable tracks with search, library-root scope,
  multi-selection, and guarded permanent deletion. Purging a track also removes
  its playlist entries, favorites, listening history/statistics, and other
  track-specific records, then cleans empty albums, artists, and genres.
  Available tracks must never be accepted by this operation. (Complete)
- Before importing a newly discovered file as a new track, compare its
  tag-independent audio-payload fingerprint against missing media across every
  enabled library root. A single unambiguous match reuses the existing media
  and track IDs, updates root/path/album relationships and parsed metadata, and
  makes retained playlist entries playable again. Preserve missing records
  when no match exists and decline ambiguous duplicate matches. Matching also
  recognizes an old physical path that disappeared before its root scan updates
  the database, making cross-root moves independent of root scan order.
  Periodic full reconciliation applies the same unavailable/relink lifecycle
  as watcher deltas. (Complete)
- Defer moving entries to another parent, delete, folder creation, and other
  write operations to a later filesystem-management phase with explicit
  conflict and rollback rules.

### 4c. Owned Copies And Discogs Matching

- Introduce one-to-many owned album copies and migrate existing physical-copy,
  format, and purchase values without data loss while retaining album-wide
  notes separately. (Complete, including independent create/edit/delete UI,
  copy-specific purchase/condition/notes fields, and album-wide note editing)
- Add a disabled-by-default Discogs connection under Settings > Connections,
  with encrypted personal-access-token storage, connection testing, disconnect,
  and clear privacy guidance. (Complete)
- Add a provider client with an identifying user agent and explicit connection
  error handling. (Complete for identity, release search/detail, and
  per-release collection lookup)
- Extend the provider client with collection/search requests, request
  throttling, retry handling, and caching/attribution behavior that follows the
  current Discogs API terms. (Complete for the bounded matching workflow with
  retries, explicit rate-limit feedback, and short-lived search/release/folder/
  instance caching. Discogs thumbnails use a host-restricted, size-validated
  local proxy cache; full collection browsing remains a separate expansion)
- Read the connected user's collection, including collection folders, exact
  release IDs, and collection instance IDs. Do not copy collection data into
  scanned album metadata. (Complete for per-release ownership: exact instances
  and collection folders are read on demand; full collection browsing remains)
- Add a Match Discogs release workflow to album personal information. Search by
  artist/title and refine with barcode, catalog number, format, country, and
  release year where available. (Complete)
- Present explicit release candidates with cover, label, catalog number,
  format, country, year, and a link to Discogs. Distinguish master releases from
  exact editions and require user confirmation. (Complete: search results are
  restricted to exact releases and never link automatically)
- Prefer an existing matching collection instance. Also allow linking an exact
  Discogs release that is not yet in the user's collection. (Complete,
  including explicit selection among duplicate collection instances)
- Display linked edition and ownership information compactly in the album's
  personal-data section, with edit, unlink, and refresh actions. (Complete with
  asynchronously loaded edition details and per-copy actions)
- Keep provider failures isolated: cached local identifiers and personal data
  remain usable, while playback, scanning, and ordinary catalog views never
  wait for Discogs. (Complete)
- Add backend fake-provider tests, migration tests, frontend matching tests, and
  an opt-in integration test that never runs in the default test suite.
  (Complete for backend fake-provider, migration, frontend store, and component
  coverage; a live-provider test remains opt-in future hardening)
- Defer Discogs collection writes, collection-condition synchronization, and
  automatic matching until read-only matching is stable.

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
- Make player metadata navigable: track title opens track detail, album opens
  album detail, artist opens the dedicated artist page, and "Now playing" jumps
  to the current track. (Complete)
- Handle unavailable files and unsupported browser codecs clearly. (Basic error feedback complete; detailed codec guidance pending)
- Decide and implement the track-title navigation model for track-centric playback. (Complete)
- Consider FFmpeg-based transcoding only after the MVP.

### 5a. Playlists and Favorites

This phase was pulled forward after the queue model became stable. It builds on the queue rather than replacing it.

- Add favorite buttons to track detail, album detail, track lists, album lists, and player affordances. (Complete)
- Add favorite track and favorite album browse sections. (Complete)
- Add optional 0.5-to-5-star personal ratings for albums and tracks. Album
  ratings appear on album details and album cards; track ratings appear on track
  details and playable track lists, but collapse below the desktop `lg`
  breakpoint to keep narrow layouts usable. (Complete for database-backed
  ratings)
- Evaluate optional rating synchronization through file metadata only behind a
  separate disabled-by-default setting. Rating tags are less interoperable than
  the existing foo_playcount fields: MP3 commonly uses `POPM`, while other
  applications and containers use incompatible scales or custom fields. The
  database remains authoritative until mappings, conflict handling, and
  round-trip fixtures are defined. (Planned follow-up)
- Add a playlist navigation section. (Complete)
- Add playlist folders for organizing custom playlists. (Foundation complete)
- Add playlist create, rename, move-to-folder, delete, and reorder workflows. (Complete)
- Add playlist create and delete workflows. (Foundation complete)
- Add ordered playlist item API for adding, removing, and reordering tracks. (Complete)
- Add "add to playlist" actions from tracks, albums, queue entries, and the player. (Complete)
- Allow creating a playlist from the current queue. (Complete)
- Add explicit sorting controls to the playlists overview, at minimum by
  folder/name, recently updated, and track count. (Complete)
- Show playlist membership for tracks that already belong to one or more
  playlists, and provide an action from track contexts to navigate directly to
  one of those playlists. (Complete for track details, track lists, and album
  track lists, including direct item focus)
- Export an album's ordered tracks as M3U8 or M3U directly beside the album
  files, using a prefilled `Album Artist - Album Title` filename and a simple
  path-only format with portable relative paths. (Complete)
- Configure a default playlist format and reusable named export folders through
  a dedicated Playlists settings tab. (Complete)
- Export custom Sonotheque playlists to a configured folder, with a selectable
  destination and format. Preserve playlist order and the active library-root
  scope; use portable relative paths on the same volume and absolute paths when
  Windows volumes differ. (Complete)
- Add disabled-by-default background synchronization of every custom playlist
  to the default export folder. Mirror nested playlist folders as filesystem
  subfolders, replace filesystem-invalid folder-name characters without
  changing names in Sonotheque, update files after playlist edits/reordering and
  recognized library-file moves, and retain the last valid export when a write
  fails. When a drive root is selected as the destination, create a named
  container folder instead of writing playlist folders directly into the drive
  root. Expose queued, successful, and failed counts with a progress bar that
  polls only while synchronization work remains. (Complete)
- Let album exports optionally use a configured playlist destination instead
  of the album folder. (Complete)
- Import external M3U/M3U8 playlists from a server-visible file picker. Resolve
  relative and absolute entries across enabled roots, preserve order and
  duplicates, allow folder assignment, and report every unmatched entry.
  (Complete)

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
  release year/date, album genres, and comments. (Complete for MP3 album title,
  album artist, release year, total discs, shared genres, and setting or
  removing the comment on every track)
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
- Display additional ID3v2 frames in track and album metadata editors and allow
  selected frames to be removed across one track or every applicable album
  track. Keep frame values separated by their user-defined description, show
  album coverage, and disable playback-statistics removals while statistics-tag
  synchronization is enabled. (Complete)
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
- Add operational visibility for Last.fm deliveries. (Complete with status
  totals, filtering, attempt and provider-error details, track links, and manual
  refresh without polling)
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
- Add a **Musicians** section to album information. Resolve an exact MusicBrainz
  release where possible, request release- and recording-level artist
  relationships, and aggregate performer, instrument, vocal, guest/additional,
  credited-as, and per-track scope without treating general band membership as
  proof of participation. Keep the lookup asynchronous and independent from
  playback. (Complete for lazy per-album retrieval, the album-information tab,
  and persisted manual selection among ambiguous MusicBrainz editions.)
- Add normalized, indexed musician identities plus album- and track-credit
  tables. Preserve source provider/release and credited names so later album
  and track filters can use stable identities rather than free-text names.
  (Complete, including indexed source-credit keys used to exclude suppressed
  provider credits from catalog counts and filters without loading the full
  collection into application memory.)
- Add manual musician-credit curation from the album page. Users may add a
  musician, select album-wide or specific-track scope, assign a role,
  retain the printed credited-as name, edit locally curated credits, and hide an
  incorrect imported credit. Imported provider rows remain immutable source
  records; local additions and suppressions form a separate effective-credit
  overlay so a background refresh can never overwrite user decisions. Preserve
  provenance in the UI and allow each hidden provider credit to be restored.
  Do not merge two musician identities by display name alone; cross-provider
  identity links must use a shared external identifier or an explicit user
  decision. (Complete: local credits and provider suppressions use separate
  normalized tables, protected CRUD endpoints, and an album dialog. Local
  credits remain available when online enrichment is disabled and both kinds
  of user decision survive MusicBrainz refreshes.)
- Supplement MusicBrainz with Discogs credits when an owned copy is explicitly
  linked to an exact Discogs release or when the selected MusicBrainz release
  links to an exact Discogs release. Extend the existing cached release payload
  to normalize release-level `extraartists` and track-level credits from the
  Discogs track list, including role, credited name variation, artist ID, and
  track position. Match track credits conservatively by release position and
  title; leave uncertain mappings album-wide or unresolved rather than attaching
  them to the wrong local track. If several owned copies link to different
  Discogs editions, let the user choose which edition supplies credits. Keep
  MusicBrainz and Discogs source records separate and combine them only in the
  effective display layer, with duplicate identities requiring a stable mapping
  or manual confirmation. Automatically import a single exact Discogs release
  linked by the selected MusicBrainz release; require an explicit choice when
  several linked editions are available. (Complete: linking the first owned Discogs edition
  imports its release- and track-level `extraartists`; albums with several
  linked editions expose an explicit source selector. A selected MusicBrainz
  release also exposes its exact Discogs release links without implying album
  ownership. Refresh/unlink operations keep normalized Discogs rows synchronized.
  Numeric positions are matched conservatively and unique normalized titles
  provide a fallback; unresolved track credits remain album-wide.)
- Version the MusicBrainz musician payload and lookup key. Existing cached album
  responses, including negative results, predate relationship retrieval and
  must be refetched once as albums are encountered. A completed current-version
  result must be reused. (Complete through a versioned per-album enrichment
  state that treats albums without a current musician lookup as pending.)
- Add a manually started musician-credit backfill after the lazy workflow is
  stable. It must be rate-limited, root-scoped, pausable, resumable,
  progress-aware, and skip current-version completed albums. Discogs credits
  may supplement an exact linked release, but providers must not be merged by
  name alone. Place the controls in **Settings > Connections** beside the
  provider configuration, with a root selector, coverage, progress,
  pause/resume, cancellation, and an ETA. (Complete: durable checkpointed runs
  process one album per queue job, preserve provider ambiguity for review, and
  report positive, negative, ambiguous, and failed outcomes.)
- Add a dedicated, root-scoped **Musician Review** page for backfill outcomes
  that need attention. Show local album context and candidate release details,
  reuse exact MusicBrainz release selection, separate ambiguous matches from
  retryable provider failures, and link to manual musician-credit curation.
  Persist explicit dismissal/no-suitable-match decisions without modifying
  imported credits, and revisit them only when requested or when the musician
  lookup version changes. (Complete: ambiguous, failed, and reviewed tabs are
  linked from the Musicians catalog and backfill summary; exact-release
  selection, retry, manual curation, dismissal, and reopening reuse the current
  root scope and preserve album back-navigation.)
- Add a dedicated **Musicians** catalog section with a searchable, root-scoped
  list of normalized musicians, album/track credit counts, and honest coverage
  context for the partially enriched collection. Link musician names in album
  information to this page, and let users browse the credited albums without
  relying on free-text matching. (Complete: the catalog includes
  A-Z navigation, persisted pagination/search state, effective credit counts,
  checked/credited album coverage, and direct musician-ID filters for album and
  track lists. Each musician has a root-scoped detail page with release-year-
  ordered albums and album-detail return navigation. The detail page summarizes
  roles/instruments, credited-as names, provider provenance, and the local
  release-year range, while each album card identifies its credit roles, scope,
  source, and guest/additional context.)
- Add a dashboard KPI for the number of distinct musicians currently present in
  effective credits. The KPI should link to the Musicians section and remain
  explicit that its value grows as lazy enrichment or the optional backfill
  covers more albums. (Complete and library-root scoped.)

### 5e. Local Audio Intelligence And Similarity

- Add an independently protected Audio intelligence settings area and durable
  validation runs that select 50-to-500 catalog tracks across enabled roots, artists,
  and genres without provisioning a model or dispatching analysis. Fingerprint
  only the selected bounded sample plus a small reserve, reuse existing
  fingerprints, and support cancellation and restart without repeating
  completed work. (Complete)
- Add an optional Python analysis worker that shares read-only access to mounted
  library roots and receives versioned jobs from Laravel. Keep the service
  stopped and unprovisioned until the user opts in. (The development Docker
  analyzer, isolated persistent service, CPU/CUDA variants, and optional
  packaged analyzer provisioning are complete.)
- Add an optional pgvector extension and separate model, feature, embedding,
  status, confidence, and error records keyed by track and audio-content
  fingerprint. (Versioned model and reusable content-addressed feature and
  embedding artifacts, status, runtime, hardware, and error records complete;
  resumable all-root or root-scoped collection scheduling is complete. The
  measured full-scale limit now has a pgvector 0.8.2 `vector(1280)` projection,
  HNSW cosine index, in-place artifact backfill, indexed query path, and visible
  coverage status. Existing JSONB embeddings remain the source of truth.)
- Validate Essentia audio features and a replaceable pretrained music embedding
  model on a representative 200-to-500-track sample before full-library
  analysis. Record model checksum, dimensions, license, runtime, and quality
  observations. (Initial 50-track CPU validation complete with 50 successes, no
  failures, and 831.7 seconds of recorded analysis time. The expanded review
  pool produced predominantly useful matches and is accepted as the go decision
  for collection analysis. A bounded expansion workflow can still grow a
  reviewed pool while carrying forward valid artifacts and selecting only the
  missing tracks.)
- Analyze multiple representative windows or a model-supported variable-length
  input so long introductions and stylistically changing tracks are not reduced
  to an arbitrary opening excerpt. (Complete for the analysis worker, including
  bounded decoding of only the selected windows and separate stage timings.)
- Invalidate analysis only when the audio-content fingerprint or analyzer model
  version changes; tag-only edits, moves, and renames must reuse it. (Complete
  for analysis artifacts, including reuse across runs and duplicate fingerprints.)
- Add exact cosine search first, then an HNSW index only after measuring query
  latency and recall on the real collection. (Complete: the initial 50-track
  exact baseline established match quality, while the full-scale measurement
  justified the pgvector HNSW cosine index now used for bounded candidate
  retrieval.)
- Add a deterministic similarity service with optional BPM, key, energy, mood,
  library-root, artist-diversity, and duplicate controls. (Partially complete:
  pgvector provides bounded cosine-neighbour retrieval plus same-album and
  same-artist exclusion. An opt-in, bounded tempo/key/intensity reranker is now
  available and keeps the original vector score visible. The current listener-
  facing baseline is complete; library-root, diversity, duplicate, and richer
  mood controls remain optional future refinements.)
- Add an inspectable, local personalization layer that learns only reranking
  weights from explicit relevant/not-relevant feedback. Completed plays, skips,
  favorites, and playlists may be evaluated later but must not affect the first
  version implicitly. Keep the base embedding unchanged, require a meaningful
  minimum feedback sample, provide reset/disable controls, and compare
  personalized results with the accepted vector and feature-refined baselines.
  (Complete for the first explicit-feedback version: training requires 20
  ratings with at least five in each class, learns bounded adjustments to the
  three visible influences, is profile-scoped, and supports independent
  enablement, re-training, and reset. Implicit behavioral inputs remain
  deliberately deferred.)
- Add Similar Tracks and Continue This Mood actions with a reviewable queue
  preview; never modify playback or playlists without confirmation. (Complete:
  Similar Tracks is available from track details, while Continue This Mood is
  available beside the current track in the queue. Both use the same scored
  preview and explicit playback confirmation; continuing preserves the current
  track and replaces only the unplayed queue tail.)
- Add audio-similarity playlist ordering. The user chooses a fixed opening
  track, and Sonotheque constructs an initial route through the analyzed tracks
  in that playlist by repeatedly selecting a close neighbour. Improve that
  route with deterministic local search such as 2-opt and track relocation so
  the result minimizes the combined cost of every adjacent transition rather
  than only making the best greedy next-track choice. Keep embedding similarity
  as the transparent baseline objective; optional artist repetition, BPM, key,
  energy, and diversity penalties may be added only as inspectable controls
  after the baseline has been evaluated. Show the proposed order and adjacent
  scores before writing anything, allow applying it to the current playlist or
  saving it as a new playlist, and retain a reversible snapshot of the previous
  order. Clearly identify tracks without a current analysis profile and keep
  their relative order in a separate trailing group unless the user excludes
  them. For unusually large playlists, bound pairwise work or use measured
  nearest-neighbour candidates. Consider seeded simulated annealing later as an
  optional **Thorough** mode only if it measurably improves accepted previews
  over greedy construction plus local optimization. The feature remains
  unavailable when Audio intelligence is disabled and never starts analysis
  implicitly. (Baseline complete: custom playlists now provide a fixed-opener
  preview using exact in-playlist vector similarities, deterministic greedy
  construction, and bounded 2-opt refinement. The preview exposes adjacent
  and aggregate scores, keeps unanalyzed entries in their original relative
  order at the end, supports applying or saving as a new playlist, and stores
  reversible order snapshots. Pairwise work is capped at 250 analyzed entries;
  inspectable transition penalties and an evidence-based Thorough mode remain
  optional future refinements.)
- Add a read-only evaluation workspace that selects an analyzed track, ranks
  exact cosine neighbours, exposes scores and feature context, and links back
  to catalog details without changing playback. Add compact feature
  distributions, same-album and same-artist exclusions, and profile-scoped
  relevant/not-relevant feedback so baseline quality can be measured before
  introducing heuristic weighting. Add a deterministic 30-source review queue
  balanced across roots, genres, artists, and albums; keep progress and quality
  metrics separate for each exclusion configuration. (Complete)
- Add Settings controls for opt-in model downloads, worker health, analysis
  progress, pause/resume, CPU/GPU limits, model replacement, and result rebuild.
  (Worker health, bounded CPU/memory settings, durable chunk progress,
  rolling wall-clock-throughput remaining-time estimates, cancellation, explicit
  pause/resume, collection/root scope, and guarded restart are complete;
  downloads remain deliberately manual. An optional, default-off CUDA image is
  packaged and benchmarked on a GTX 1070; it preserves the existing analyzer
  profile and reusable artifacts. A separately opt-in persistent, networkless
  Docker service now keeps the model loaded between chunks, uses read-only root
  mounts and an internal Unix socket, validates its image/model/resource
  identity, recovers through the same accelerator, and is removed when a run
  stops. CUDA model patches are now batched across all representative windows
  in a request, and a bounded two-worker CPU preparation pipeline overlaps
  decoding and feature extraction with serialized GPU inference. A controlled
  five-track comparison reduced wall time from 106.2 to 52.4 seconds with
  byte-for-byte identical embeddings and matching features. The pipeline is
  strictly CUDA-only; the default CPU path remains sequential and never
  requests GPU devices. Model upgrades create isolated, versioned profiles and
  preserve the best-covered prior profile for similarity features until the new
  profile reaches equal coverage; completed artifacts from either generation
  remain reusable.)
- Add a CPU-versus-CUDA benchmark to Advanced diagnostics. Run both analyzers
  against the same small, bounded set of readable tracks without modifying
  collection-analysis progress or stored artifacts. Report image/GPU health,
  wall-clock and per-stage timings, throughput, and the measured speed
  difference. Verify that generated embeddings remain within a strict cosine
  tolerance before CUDA can be recommended. (Complete: Advanced diagnostics
  runs a cancellable six-configuration background benchmark on the same 15
  readable tracks. It compares the CPU baseline with CUDA preparation workers
  1/2/3, then tests chunk sizes 10/15 using the first-stage winner. Results,
  throughput, verification, unavailable hardware, and the recommendation are
  durable. A paused collection run remains untouched, and no artifacts or run
  progress are written.)
- Provide an explicit analyzer-method selector for CPU or CUDA, display
  availability and the benchmark recommendation beside it, and persist the
  user's choice. Keep the active method unchanged until the user applies a
  selection; never switch or fall back silently. A change applies to future
  chunks without interrupting an active chunk or invalidating reusable
  artifacts. Explain clearly when CUDA is unavailable or slower. (Complete:
  the persisted selector uses benchmark availability and recommendations,
  applies to future work, and never falls back silently.)
- Split listener-facing controls from experimental diagnostics. The normal view
  should show enablement, coverage, collection-run progress, pause/resume,
  the execution method, and actionable recommendation preferences. Analyzer
  details, benchmarks, validation runs, pool expansion, distributions, and
  baseline evaluation should remain collapsed under Advanced diagnostics and
  need not be used by a normal listener. (Complete)
- Verify that a default installation performs no model downloads, starts no AI
  services, schedules no analysis jobs, and has no additional steady-state CPU,
  GPU, or model-memory usage. (Complete for the analyzer boundary: loading
  Settings dispatches no jobs or processes and performs no health probe; no AI
  task is scheduled; analyzer checks require explicit opt-in; disabling requests
  pauses for active preparation/analysis and cancellation for benchmarks; and
  queued jobs recheck the persisted setting before fingerprinting or starting an
  analyzer. The isolated Laravel analysis-queue worker remains idle as shared
  application infrastructure, but no Python or Docker analyzer service is
  started.)
- Add fixtures and quality tests for analysis invalidation, model upgrades,
  duplicate handling, unavailable workers, and playback independence.
  (Complete: coverage includes fingerprint/profile reuse, guarded resume,
  disabled jobs, unavailable workers, profile-isolated model upgrades with
  best-coverage cutover, and streaming that remains independent of analyzer
  availability or failure.)

The current Audio Intelligence milestone is complete. Its production baseline
is optional and disabled by default, resumable and content-addressed, CPU-safe
when CUDA is unavailable, explicitly benchmarked and selectable, profile-safe
across model upgrades, and isolated from playback. Further reranking controls or
playlist-order strategies are evidence-driven refinements rather than blockers.

### 5f. Local Collection Assistant

- Add an optional local-LLM adapter with Ollama as the first candidate and keep
  the provider interface replaceable. Keep it independently disabled by default
  and never start or download a model implicitly. (Foundation complete:
  persisted opt-in and model selection, environment-controlled endpoint,
  explicit installed-model discovery, and a real tool-call capability check.
  Guarded backend conversation execution and the initial user-facing view are
  complete.)
- Expose a small allowlist of structured Laravel tools for catalog searches,
  aggregates, listening history, similar tracks, and queue previews. Never expose
  raw SQL, filesystem paths, provider secrets, or unrestricted APIs. (Initial
  collection-summary, bounded catalog search, listening totals, track/album/
  artist/genre rankings, recent history, unplayed-album, and root-scoped
  similar-track tools are complete. Unambiguous collection totals bypass the
  model while retaining the same validated tool boundary. Similarity-backed
  play-now and add-to-queue previews are complete; broader generated selections
  remain a future extension.)
- Validate tool schemas, result limits, library-root scope, timeouts, and the
  maximum number of tool iterations server-side. (Complete for the initial
  tool registry and Ollama conversation loop.)
- Require linked catalog evidence for factual collection answers and distinguish
  database facts, model interpretations, and uncertain audio-analysis labels.
  (Linked references from trusted catalog tools are complete. Similarity results
  identify their analyzed coverage, ranking method, raw and refined scores, and
  explicitly state that scores are rankings rather than probabilities.)
- Add a dedicated Collection Assistant view with conversational history kept
  locally and explicit confirmation for any generated queue, playlist, or
  playback action. (The root-aware view, bounded local history, safe Markdown
  rendering, linked evidence, model warm-up, and concise synthesis path are
  complete. Similarity requests can return a bounded, verified playback preview;
  Sonotheque changes the browser queue only after explicit confirmation, and the
  model never receives direct mutation access.)
- Support English and German questions while normalizing semantic audio prompts
  to the language expected by the configured embedding model. (Pending)
- Add disabled/unavailable/model-error states that leave normal browsing and
  playback fully functional. (Complete for setup and the initial Assistant
  view.)
- Consider opt-in lightweight personalization only after explicit recommendation
  feedback and evaluation controls exist. Do not train a foundation model from
  the private collection. (Ready to implement: explicit feedback and baseline
  evaluation are complete; the first version remains a small local reranker.)

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
  (Complete, including a repeatable LAN preflight and physical-device browsing,
  playback, and anonymous-administration verification)

### 8. Testing and Packaging

- Unit-test path validation, metadata mapping, and incremental scan decisions. (Partially complete)
- Test folder-based cover discovery, embedded-artwork fallback, and thumbnail generation. (Complete at feature-test level)
- Feature-test API filters, scan operations, favorites, playlists, dashboard metrics, artwork, and range streaming. (Complete)
- Use small MP3, FLAC, Ogg, and malformed-file fixtures. (Partially complete)
- Add frontend store and component tests. (Store tests complete for catalog, roots, scans, preferences, player, favorites, and playlists)
- Add end-to-end coverage for configuration, scanning, browsing, and playback.
  (Complete for packaged empty-install setup, optional metadata configuration,
  scanning, folder browsing, large folder confirmation, subtree scan
  cancellation, `v0.1.0` upgrade preservation, real streaming, seeking, queue
  progression, track switching, and refresh restoration)
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
  and failed scans, and failed queue jobs. Native local/LAN startup now also
  supervises and automatically restarts exited queue workers while preserving
  their prior logs; packaged queue services use Docker restart policies.)
- Add manual backup and restore commands for PostgreSQL data and application
  storage. (Complete with checksummed development and packaged bundles,
  APP_KEY preservation, safety backups, and Settings status)

## Recommended Next Step

Finish cross-platform release hardening with practical macOS Docker Desktop and
removable-drive verification, then fold confirmed hardware results back into
`docs/platform-support.md`. The shared POSIX lifecycle, roots, backup/restore,
Audio Intelligence provisioning, native picker fallbacks, Linux ownership,
Ollama routing, TAR release, Ubuntu smoke checks, and explicit CPU/CUDA and
architecture support policy are now in place. Optional analysis stays disabled
when the host cannot build or run its native analyzer; Sonotheque never silently
uses emulation or a different accelerator.

The filesystem-monitoring milestone is complete: watcher events now create
durable multi-path delta runs, missing files retain their catalog and playlist
identity as unavailable entries, and unambiguous audio fingerprints reconnect
cross-root moves without depending on root processing order. Real-collection
observation and periodic full reconciliation remain operational safeguards, not
unfinished implementation milestones.

The current Audio Intelligence milestone is also complete for the development
runtime. Normal collection analysis no longer depends on the earlier validation
stage; validation, bounded pool expansion, benchmarking, and structured review
remain optional Advanced diagnostics. The musician-credit backfill and its
centralized, root-scoped ambiguous/failure review workflow are complete.
Packaged delivery of the optional analyzer is complete. It is disabled by
default, uses CPU unless CUDA is explicitly selected, keeps the reviewed model
outside the package, and limits Docker access to the dedicated analysis worker.

The Collection Assistant foundation and read-only similarity integration are
complete. Ollama remains independently
optional and disabled by default; installed models are discovered and verified
explicitly, with `qwen3:4b` recommended as the responsive tool-capable starting
point. Conversations are separated by library-root scope and retain bounded
browser-local context. Guarded tools cover collection totals, catalog search,
all-time and period listening statistics, recent history, unplayed albums, and
track/album/artist/genre rankings. Common total-count questions avoid an LLM
round trip, while arbitrary questions use a bounded tool-selection and concise
result-synthesis flow. Named reference tracks can now be resolved safely for
root-scoped pgvector similarity search; ambiguous, disabled, and unanalyzed
states remain explicit, and results include coverage, ranking method, raw and
refined scores, uncertainty, and verified track links. Similarity-based play-now
and add-to-queue requests now produce a verified preview with explicit Confirm
and Cancel controls. Playable payloads remain outside model context, and the
model has no direct mutation access.

The expanded similarity review produced predominantly useful matches and is
accepted as the go decision for the unweighted embedding baseline. Sonotheque
now supports explicit collection analysis across all enabled roots or one
selected root. Candidate enumeration, fingerprint preparation, and analysis
are durable and pausable. Each run records its scope and checkpoint; resume
continues the same run, while current fingerprints and analyzer-profile/content
artifacts are reused across overlapping scopes.

Collection, validation, and pool-expansion runs now have separate API state and
UI ownership. The validation sample size is persistent, while a pool-expansion
target is a one-off request and is hidden after the 500-track review ceiling is
reached. Validation and pool expansion have served their initial model-selection
purpose and should now be treated as experimental diagnostics rather than a
normal-user workflow.

The first root-scale collection run is effectively complete and is sufficient
to continue feature development; roots analyzed later expand the candidate pool
without invalidating existing artifacts. Root-scoped status in Settings must
always follow the selected root rather than displaying the globally latest run.

The full-scale latency measurement established the need for indexed nearest-
neighbour search: loading 33,148 1,280-dimensional JSON embeddings into PHP
exceeded the 128 MB request memory limit, while streaming the same vectors
exceeded the 30-second request timeout. This milestone is complete with a pinned
pgvector PostgreSQL 18 image, transactional in-place vector backfill, HNSW cosine
index, bounded nearest-neighbour candidate retrieval, exact catalog filtering,
and automatic indexing of new artifacts. Existing files were not analyzed
again. Warm similarity requests over 33,164 vectors complete in well under one
second on the development machine instead of failing after 30 seconds.

The standard Audio intelligence workflow includes an explicit persisted
CPU/CUDA method selector. It preserves the installation's previous environment
choice on upgrade, shows benchmark-derived availability and recommendation
state, applies to newly started or resumed analysis jobs, and never falls back
silently. The benchmark itself remains in Advanced diagnostics.

Optional feature reranking is now implemented and remains disabled by default.
It expands only a bounded nearest-neighbour pool, applies configurable maximum
penalties for tempo, key, and intensity compatibility, and exposes both vector
and final scores. Missing features do not penalize a candidate and half/double
tempo is compatible. The first local personalization layer is now complete: it
uses only explicit ratings, requires a balanced minimum sample,
stores analyzer-profile-specific adjustments bounded to three points per
visible influence, and can be enabled, re-trained, disabled, or reset without
changing embeddings. Behavioral signals remain out of scope until separately
evaluated.

Audio-similarity playlist ordering from a user-selected opening track is now
implemented with deterministic nearest-neighbour construction followed by
bounded 2-opt refinement. The preview is reviewable and reversible, supports
applying the order or saving it as a new playlist, and retains unanalyzed tracks
in their relative order at the end. Seeded simulated annealing remains reserved
for a measured optional Thorough mode rather than being part of the baseline.

The standard settings surface is now limited to enablement, collection
coverage/progress, the execution method, refinement, and personalization.
Analyzer health and model details, benchmarks, validation/pool tools,
distributions, and similarity evaluation share one collapsed Advanced
diagnostics area. GPU acceleration remains an optional independent optimization.
The packaged CUDA adapter, persistent networkless service, CPU-preparation
pipeline, and benchmark are complete; they preserve artifact identity and do
not force completed audio to be analyzed again.

Playlist file import, export, and custom-playlist synchronization are complete.
Imports accept simple and extended M3U/M3U8 files, resolve paths relative to the
source playlist, preserve matched order, and report every unmatched entry.
Album exports can target either the album directory or one of the configured
export folders while retaining portable relative paths whenever possible.

The owned-copy and read-only Discogs matching workflow is complete. Albums may
contain multiple independently editable physical or digital copies, and every
copy can link to its own exact Discogs edition. Matching supports explicit
selection among duplicate collection instances, compact cached edition details,
locally cached and validated thumbnails, and manual refresh without making
album browsing depend on Discogs. Full
collection browsing, writes to a Discogs collection, condition synchronization,
and automatic matching remain later, separately confirmed features.

The durable metadata backup policy is complete: it is disabled by default,
uses a configurable location and retention period, preserves source-relative
paths in unique copies, records checksums and edit ownership, exposes recovery
details, and provides cleanup and path-checked restore commands.

The planned MP3 metadata field set is complete. Album track selection now adds
bulk playback, queue, playlist, favorite, and metadata actions; selected-track
metadata edits show common and mixed values and only write explicitly enabled
fields. A browsable backup audit in Settings is deferred while the existing
command-based recovery workflow remains sufficient.

The first Last.fm connector milestone is complete: account authorization,
encrypted local credentials, opt-in scrobbling, shared local/Last.fm eligibility
rules, asynchronous delivery, and a filterable delivery log with status totals,
attempt details, and provider errors are implemented. History import and
now-playing updates remain optional later refinements.

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
Artist-originated album and track navigation preserves the active tab and both
page positions; track details also provide non-wrapping previous/next navigation
across the artist's complete root-scoped track order.
Optional artist portraits are resolved from MusicBrainz IDs through Wikidata,
downloaded from Wikimedia Commons through a host-restricted Laravel proxy,
attributed, validated, cached privately, and shown with a local fallback.
Additional provider fallback remains a later refinement. Lazy MusicBrainz
**Musicians** retrieval is now available from album information. It persists
normalized release-wide and recording-scoped performance credits, excludes
ordinary membership relationships, and uses a versioned per-album state so
existing MusicBrainz album cache entries are refetched once when encountered.
Ambiguous searches now retain compact release candidates and let the user select
or later change the exact edition; that choice is reused for subsequent cached
refreshes. Album pages also provide a local musician-credit editor: user-owned
album-wide or track-scoped credits and per-source suppressions are kept separate
from imported MusicBrainz rows, visibly attributed, and retained across provider
refreshes. Exact owned Discogs editions may now supplement those records with
release- and track-level credits. MusicBrainz-linked Discogs releases can also
supply credits without implying ownership; exact owned editions remain the
preferred source when available. The selected source is explicit, provider
identities remain separate, and imported Discogs rows are refreshed or removed
with their source link. A dedicated, root-scoped **Musicians** catalog now shows
effective album and track credit counts, partial-collection coverage, and links
to root-scoped musician detail pages with release-year-ordered credited albums.
Album navigation preserves a direct return to the musician, while the catalog
also offers musician-ID-filtered album and track lists. The dashboard includes
the same effective musician count. Settings > Connections now provides an
optional root-scoped musician backfill with honest coverage, durable
checkpoints, five-second background progress updates, pause/resume/cancel
controls, provider outcome counts, and an ETA. Current-version completed albums
are never requested again.

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
physical-copy filters in album and track lists. Track-to-playlist membership
navigation and explicit sorting controls are now complete for albums, tracks,
and grouped playlists.

The root-scoped folder browser is complete for its read-and-play phase: guarded
APIs expose only relative paths, large directory listings are virtualized,
indexed files and recursive folder contents reuse the existing player and
playlist workflows, large actions use a count-only confirmation preflight, and
subtree scans expose progress and cancellation while keeping cleanup constrained
by PostgreSQL-backed tests. An isolated packaged Playwright suite verifies setup,
real fixture scanning and navigation, large-action confirmation, and scan
cancellation. Same-parent file and folder renames are now the first guarded
filesystem write: they preserve catalog identity and reject collisions, unsafe
names, extension changes, excluded paths, and active scans. Full-root scans now
reconcile unique external moves by a versioned audio-payload fingerprint while
ignoring mutable ID3/APEv2 data; ambiguous duplicate matches remain conservative.
Normal scans fingerprint new and changed files but deliberately do not backfill
missing legacy fingerprints inline. The one-time restartable maintenance
backfill for the current catalog is complete; seven malformed files could not
be fingerprinted and therefore remain ineligible for external move
reconciliation. Subtree scans can only reconcile moves wholly inside their
scope.
Discovery now writes a validated temporary manifest during counting and reuses
it for processing, avoiding a second filesystem walk; playback-tag imports are
versioned so unchanged cached metadata is not parsed repeatedly. Moving between
parents inside the UI, deletion, and folder creation remain deferred.
Last.fm delivery visibility is complete, while the browsable metadata-backup
audit remains deferred. Packaged upgrade preservation from `v0.1.0` and real
browser playback are now verified automatically. The packaged suite also starts
from an empty installation, drives the resumable first-run configuration,
creates and scans its mounted root, and supplies that state to the dependent
folder and playback projects.

The LAN authorization boundary, browser token workflow, trusted-host checks,
CORS allowlist, explicit startup mode, proxy-aware client IP handling, Windows
Firewall guidance, packaged Compose runtime, startup scripts, resumable
first-run setup, health checks, and checksummed manual backup and guarded
restore for PostgreSQL and application storage are complete. The initial
versioned Windows portable bundle, double-click launcher, host-folder picker,
installation guide, and release checksum are also complete. LAN verification
was completed from a second physical device on the local network,
with browsing and playback available and protected administration denied
without a token. Version `v0.1.0` has
been published as the first live GitHub Release. Repeatable release builds and
GitHub publication are automated by a tag-driven workflow that runs backend and
frontend validation before building and auditing the Windows portable archive.
Packaged multi-root mount management uses a generated Compose override with
stable `/music/root-N` mappings and a native Windows folder-selection flow.

## First Milestone Definition (Complete)

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
- Keep personal album and track ratings in the database as the source of truth.
  File-tag import/export is optional and disabled by default because rating
  frames and numeric scales are not standardized consistently across players.
- Treat the selected library root as session-level query context, with all roots
  as the default, rather than modifying or duplicating catalog records.
- Keep personal album information separate from scanned metadata so filesystem
  scans and tag edits cannot overwrite it.
- Derive folder navigation from guarded, lazy filesystem listings enriched by
  catalog records rather than storing a duplicate folder tree.
- Identify folders by library-root ID and normalized relative path; never accept
  arbitrary absolute paths from the catalog browser.
- Keep the first folder view read-oriented, with playback, playlist, and scoped
  scan actions but no filesystem mutations.

## Final Deferred Milestone: Optional Remote Access and Trusted Devices

This milestone deliberately comes last. Implementation must not begin until all
other planned local features, their tests, and the corresponding release work
are complete. Its external networking and security dependencies should not
shape or delay the core local application before then.

Remote access is feasible with the current Laravel, Vue, PostgreSQL, and
packaged Compose architecture, but it is a separate security mode rather than
an extension of development LAN mode. It must be disabled by default and must
never expose Vite, `artisan serve`, PostgreSQL, queue workers, or mounted music
folders directly to the Internet.

Two deployment paths should be supported:

1. **Private overlay access (recommended):** document and optionally integrate
   a WireGuard-based overlay such as Tailscale. Device admission can then be
   enforced by the overlay network's own device-approval and access-control
   system. Sonotheque still applies its read-only remote-device authorization,
   but no router port forwarding or public Sonotheque endpoint is required.
2. **Direct HTTPS gateway (advanced):** expose only the packaged reverse proxy
   on TCP 443, use a domain or dynamic-DNS name, terminate valid TLS at a
   production reverse proxy, and forward requests to the existing same-origin
   frontend and Laravel API. Startup must refuse this mode when TLS, trusted
   proxy, host allowlist, and persistent secrets are incomplete.

The trusted-device workflow for either path should be:

- An untrusted browser can access only the enrollment page and narrowly scoped
  request/status endpoints. Catalog, artwork, audio, enrichment, and all
  mutation endpoints remain unavailable until approval.
- The browser creates a pending request with a user-supplied device label. The
  backend returns a short verification code plus an opaque, one-time polling
  secret. Requests expire after a short period and are rate-limited by source
  address and request identifier.
- A local administrator reviews the device label, verification code, request
  time, source address, and user agent in Settings. Approval and rejection are
  available only from localhost or through the existing admin-token boundary.
- After approval, the waiting browser exchanges its one-time secret for a
  random, revocable device session in a `Secure`, `HttpOnly`, same-site cookie.
  Only hashes of persistent secrets are stored. Approval tokens are single-use,
  device sessions rotate, and clearing browser data requires enrollment again.
- Settings lists approved, pending, expired, and revoked browsers with creation,
  approval, last-use, and expiry timestamps. Administrators can revoke one
  browser or all remote sessions immediately.
- A browser enrollment represents one browser profile, not cryptographic proof
  of a physical device. Optional WebAuthn-bound credentials can be evaluated
  later if stronger device identity becomes necessary.

Authorization must be enforced by Laravel rather than only by hidden frontend
controls:

- Introduce explicit capabilities such as `catalog.read`, `media.stream`, and
  optional `listening-history.write`. Remote devices receive only the minimum
  configured capabilities.
- Apply a deny-by-default remote middleware boundary to every API route. Initial
  read-only access allows catalog browsing, artwork, cached enrichment, lyrics,
  and range-based audio streaming. Library configuration, scans, folder
  browsing and writes, metadata editing, trash, backups, connections, device
  administration, favorites, playlists, and personal album data remain denied.
- Theme and language stay available because they are browser-local preferences.
  Administrative settings tabs, write actions, and their routes are hidden for
  read-only sessions, but backend authorization remains authoritative.
- Decide explicitly whether remote playback contributes to Sonotheque history,
  play counts, and Last.fm scrobbles. Treat it as a separate capability and
  leave it off for the initial read-only mode.

Before release, add:

- CSRF protection for cookie-authenticated requests; strict host, origin, and
  trusted-proxy validation; secure response headers; bounded request bodies;
  enrollment and streaming rate limits; concurrent-stream limits; and
  structured security audit events.
- Feature tests that enumerate every API route for anonymous, pending,
  read-only, revoked, and administrator contexts so a newly added write route
  cannot accidentally become public.
- Packaged end-to-end tests for request, approval, cookie rotation, expiry,
  revocation during playback, hidden frontend actions, HTTP range streaming,
  and failed brute-force attempts.
- Operational documentation for dynamic DNS, router or CGNAT limitations,
  certificate renewal, firewall rules, bandwidth expectations, token recovery,
  incident response, and disabling remote access.

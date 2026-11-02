# Changelog

## Unreleased

## 0.5.2 - 2026-11-02

- Supersede the unpublished 0.5.0 and 0.5.1 candidates without rewriting their tags.
  Make packaged playback assertions target the visible accessible track link
  rather than also matching a hidden responsive copy of the title.
- Correct portable archive auditing so backup implementation classes are not
  mistaken for private backup files, while rejecting actual private storage.
  Exclude all backend runtime storage, local packaged secrets, and downloaded
  analysis models from Docker build contexts.

- Include the previously unpublished 0.4.0 work: Linux/macOS portable packaging,
  optional packaged Audio Intelligence, recent playlist destinations, and player
  position and volume fixes. Apple-silicon hardware verification remains pending.
- Add half-star album and track ratings with optional, disabled-by-default MP3
  tag synchronization, compact responsive controls, and protected managed tags.
- Add normalized record labels and catalogue numbers with embedded-tag import,
  album editing, provider suggestions, provenance, and label-filtered playback.
  Share exact MusicBrainz release selection across labels, musicians, and album
  information, and show release track counts when choosing an edition.
- Create and restore checksummed backups from Settings with queued progress,
  confirmation, maintenance mode, safety backups, and rollback on restore failure.
- Improve metadata editing for case-only artist corrections, inconsistent album
  tags, custom-tag value separation, publisher verification, and managed-tag
  removal protection.
- Show catalog added/updated dates, album first-played dates, artist origin, and
  clearer encoding information, including historical LAME preset labels.
- Improve random-album selection and playback recovery, preserve search focus
  when restoring filters, and show progressive album artwork loading with a
  bottom progress bar. Enlarged artwork has a grayscale background.
- Speed up large-catalog search and relationship lookups with improved query
  plans, a covering index, and more frequent PostgreSQL table maintenance.
- Fix add-to-playlist dialogs inside playlists, isolate list/detail loading,
  ignore stale detail responses, and show loading instead of a premature
  playlist-not-found message.
- Recover queue workers and the scheduler after temporary PostgreSQL outages or
  Docker Desktop restarts. Detach native supervision from the launching
  terminal, bound Docker health checks, and respect intentional manual shutdown.
- Update Audio Intelligence for newer CUDA hardware, retain CPU compatibility,
  persist benchmark tuning, expose the selected configuration, and improve
  analysis startup and resume behavior.
- Recognize complete secondary ID3 blocks without importing their older values
  or reporting their bytes as unexplained garbage. Preserve raw diagnostics and
  warnings for genuinely malformed regions; scans never remove source bytes.
  Correct UTF-16 rating decoding without guessing unsupported rating scales.
- Refactor frontend detail, player, and settings workflows into focused shared
  components and composables, with expanded regression coverage and Windows
  background-service recovery checks in the release workflow.

## 0.5.1 - 2026-11-02

- Unpublished candidate with corrected packaged playback selectors. Archive
  auditing incorrectly classified backup source code as private data;
  superseded by 0.5.2.

## 0.5.0 - 2026-11-02

- Unpublished release candidate. Application validation passed, but packaged
  playback verification found ambiguous responsive-title test selectors.
  Superseded by 0.5.1, which includes all of its features and fixes.

## 0.4.0 - 2026-08-17

- Allow album playlist exports to use either the album folder or any configured
  playlist export folder, with shared portable path handling.
- Add disabled-by-default packaged Audio Intelligence setup with CPU and
  explicit CUDA analyzer builds, a reviewed read-only model mount, queued
  health checks, and Docker access isolated to the dedicated analysis worker.
- Begin Linux and macOS packaged support with a shared tested configuration
  core, a POSIX lifecycle and folder launcher, Linux-safe host ownership,
  native-Linux Ollama routing, compatible checksummed backup/restore, guided
  Audio Intelligence setup, native picker fallbacks, POSIX smoke coverage, an
  explicit support matrix, and verified TAR release artifacts alongside the
  Windows ZIP.
- Restore the saved playback position reliably after a browser refresh and
  prevent repeated player collapse and expansion from stacking Web Audio output
  routes and increasing the audible volume.
- Show the five most recently used playlist destinations as browser-local quick
  choices in the add-to-playlist dialog.
- Normalize historical LAME extreme-preset strings with additional encoder
  options to the familiar `V0` quality label.
- Replace user-specific PHP executable paths in repository instructions with a
  portable WinGet lookup based on Windows environment variables.

## 0.3.0 - 2026-08-16

- Add explicit Collection Assistant playback previews for similar-track
  requests, with verified catalog tracks and confirmation before replacing or
  extending the browser queue.
- Add a comprehensive project README covering Sonotheque's features,
  technology stack, setup paths, security scope, and AI-assisted development.
- Refresh supported backend and frontend dependencies, including Laravel, API
  Platform, Vue, Vuetify, Vue Router, Vite, Vitest, Tiptap, and development
  tooling, with clean npm and Composer security audits.
- Raise the frontend development requirement to Node.js 22.18 to match the
  updated toolchain while keeping packaged builds on the rolling Node 22 image.
- Document the development-to-master release workflow and require release
  notes for each published version.
- Update packaged and upgrade browser verification to start the split default,
  scan, and analysis queue workers used by the current runtime.

## 0.2.0 - 2026-08-16

- Add optional per-root filesystem monitoring with consolidated activity logs,
  targeted reconciliation scans, serialized dispatch, and durable watcher
  state transitions.
- Add resilient single-pass scan discovery manifests, versioned metadata
  import checkpoints, and a one-time restartable fingerprint baseline for
  reliable external move detection without slowing routine scans.
- Add M3U/M3U8 playlist import, configurable export destinations, and optional
  asynchronous playlist-file synchronization that mirrors playlist folders.
- Add owned-copy records and exact Discogs release matching with cached edition
  details and artwork.
- Add disabled-by-default local audio intelligence with resumable collection
  analysis, similarity review, CPU/CUDA benchmarking, and reusable
  content-addressed analysis artifacts.
- Complete Audio Intelligence with pgvector similarity search, transparent
  refinement and personalization, reversible playlist ordering, safe model
  profile cutover, and collection analysis independent from validation tools.
- Add an optional Ollama-powered collection assistant with fast catalog and
  listening-statistics tools, conversation context, and audio-similarity
  recommendations.
- Add musician credits sourced from MusicBrainz and Discogs, including a
  musician catalog, album associations, resumable backfill, ambiguity review,
  manual corrections, and coverage reporting.
- Add a root-scoped folder view with lazy directory navigation, virtualized
  large-folder rendering, file/folder playback and playlist actions, and safe
  subtree rescans.
- Correctly verify UTF-16 ID3 comments and normalize legacy numeric genre
  references during metadata editing and scans, with parser-version invalidation
  so the next scan refreshes affected unchanged files once.
- Show track playlist memberships and navigate directly to the matching playlist item.
- Improve playlist workflows with drag-edge scrolling, reliable navigation to
  newly added tracks, searchable add dialogs, and playlist playback insights.
- Normalize non-standard little-endian ID3 play counters during statistics import.
- Exclude global popularity fields that are ambiguously tagged as personal play counts.
- Clarify LAN admin-token verification and the server-token recovery workflow.
- Add persisted sorting controls for album, track, and grouped playlist views.
- Improve large-catalog search responsiveness with indexed prefix/trigram
  lookups, optimized pagination totals, and cancellable debounced searches.
- Rename internal runtime, database, and interface identifiers from the generic
  music-library name to Sonotheque.
- Preserve expected authorization status codes during Windows LAN startup
  verification.

## 0.1.0 - 2026-07-13

Initial portable release candidate with:

- local library scanning and metadata management;
- artist, album, track, genre, favorites, history, and playlist views;
- browser playback, queue management, and visualization;
- playback statistics and optional metadata synchronization;
- Last.fm, MusicBrainz, and lyrics enrichment;
- Docker-based local and LAN runtime modes;
- guided multi-folder host mounts with stable container paths;
- guided first-run setup, health diagnostics, backup, and restore tooling;
- reproducible tag-driven GitHub Release builds with tests and checksum audits.

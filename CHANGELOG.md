# Changelog

## Unreleased

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

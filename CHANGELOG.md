# Changelog

## Unreleased

- Add a root-scoped folder view with lazy directory navigation, virtualized
  large-folder rendering, file/folder playback and playlist actions, and safe
  subtree rescans.
- Correctly verify UTF-16 ID3 comments and normalize legacy numeric genre
  references during metadata editing and scans, with parser-version invalidation
  so the next scan refreshes affected unchanged files once.
- Show track playlist memberships and navigate directly to the matching playlist item.
- Normalize non-standard little-endian ID3 play counters during statistics import.
- Exclude global popularity fields that are ambiguously tagged as personal play counts.
- Clarify LAN admin-token verification and the server-token recovery workflow.
- Add persisted sorting controls for album, track, and grouped playlist views.
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

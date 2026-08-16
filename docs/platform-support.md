# Platform Support

Sonotheque's portable package uses the same Docker Compose application on each
host. Platform launchers configure host folders and local ownership, while the
catalog, database, frontend, and queue workers remain identical.

## Current Matrix

| Host | Base Sonotheque | CPU Audio Intelligence | CUDA Audio Intelligence | Status |
| --- | --- | --- | --- | --- |
| Windows 10/11, x86-64 | Supported | Supported | Supported with a compatible NVIDIA GPU exposed to Docker Desktop | Regular development and packaged testing |
| Ubuntu Linux, x86-64 | Supported | Supported when the Essentia image builds | Supported with NVIDIA Container Toolkit | POSIX configuration and packaged browser workflows run in CI |
| Other Linux, x86-64 | Expected to work with Docker Compose v2 | Build-dependent | NVIDIA Container Toolkit required | Community preview |
| macOS, Intel | Expected to work with Docker Desktop | Build-dependent | Not available | Manual preview; Docker Desktop validation required |
| macOS, Apple silicon | Expected to work when every base image has an ARM64 variant | Not currently guaranteed | Not available | Experimental |
| Linux, ARM64 | Expected to work when every base image has an ARM64 variant | Not currently guaranteed | Not currently supported | Experimental |

"Build-dependent" means Audio Intelligence remains disabled unless its
optional Essentia/TensorFlow image builds successfully on that machine. A
failure does not prevent Sonotheque itself from starting, scanning, or playing
music. Sonotheque does not silently run an emulated analyzer or switch from
CUDA to CPU, because that would make performance and analysis provenance
unclear.

## Host Requirements

- Docker Engine and Docker Compose v2. Docker Desktop is the supported route
  on Windows and macOS.
- Enough Docker disk space for application images, PostgreSQL, thumbnails, and
  optional analyzer images.
- Read/write sharing of every music or playlist-export folder that Sonotheque
  is expected to modify. macOS users must explicitly share external locations
  with Docker Desktop.
- A user account that can run Docker without `sudo` on Linux. The launcher
  records that user's UID/GID so files written into music mounts retain useful
  host ownership.

## Verification Levels

Tagged releases run backend, frontend, packaged Playwright, upgrade, POSIX
configuration, backup-manifest, launcher syntax, and Compose-generation checks
on Ubuntu. Windows release archives are built and structurally verified on a
Windows runner. macOS Docker Desktop behavior, removable volumes, and native
pickers currently use this manual release checklist:

1. Extract the TAR into a permanent folder and run `./sonotheque start`.
2. Select a folder with the macOS picker and confirm Docker Desktop can mount
   it as `/music/root-1`.
3. Complete setup, scan a small fixture, play and seek through two tracks, and
   write a playlist into a shared host folder.
4. Run `./sonotheque backup`, validate that Settings records it, then restore
   it with `./sonotheque restore PATH --force`.
5. Stop and restart the package and verify the database, storage, mount order,
   and browser URL are preserved.

This matrix should be tightened as real hardware reports and self-hosted test
runners become available. Unsupported optional analysis hardware must always
leave the base application usable.

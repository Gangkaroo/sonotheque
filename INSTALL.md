# Sonotheque Installation

## Shared Requirements

- Docker Engine with Docker Compose v2
- Enough free disk space for Docker images, PostgreSQL data, and generated
  thumbnails

PHP, Composer, Node.js, PostgreSQL, and nginx do not need to be installed on the
host. They are included in the Docker package.

## Windows Requirements

- Windows 10 or Windows 11
- Docker Desktop with Linux containers enabled

## Windows First Start

1. Start Docker Desktop and wait until it reports that the engine is running.
2. Extract the release ZIP into a permanent folder, for example
   `C:\Sonotheque`. Do not run it directly from the ZIP or a temporary folder.
3. Double-click `Start Sonotheque.cmd`.
4. Select the host folders containing your music when prompted. After each
   selection, Sonotheque asks whether another folder should be added.
5. Wait for the first Docker build to finish. This takes longer than later
   starts because the application images must be downloaded and built.
6. The browser opens `http://127.0.0.1:8080/setup` automatically.
7. Complete the setup wizard, review metadata-writing options, and start the
   first library scan.

The selected host folders are mounted into the application as `/music/root-1`,
`/music/root-2`, and so on. Music remains in its original location and is not
copied into the package.

## Linux And macOS First Start

1. Install and start Docker. On macOS, use Docker Desktop. On Linux, make sure
   the current user can run `docker version` without `sudo`.
2. Extract the Linux/macOS TAR archive into a permanent folder.
3. Open a terminal in that folder and run:

   ```sh
   ./sonotheque start
   ```

   If archive permissions were removed while copying the package, run
   `chmod +x sonotheque` once first.
4. Select the music folder with the native picker. On Linux without `zenity` or
   `kdialog`, enter its absolute path in the terminal. You can also pass one or
   more folders directly:

   ```sh
   ./sonotheque start --music-root "/mnt/music" --music-root "/media/archive"
   ```

5. The launcher opens `http://127.0.0.1:8080/setup`. Complete the setup wizard
   and start the first scan.

On macOS, every external music location must be shared with Docker Desktop.
On Linux, Sonotheque records the current UID and GID so newly created playlist
files and metadata backups retain the desktop user's ownership. Do not run the
launcher with `sudo`.

## Adding Or Changing Music Folders

Double-click `Configure Sonotheque Folders.cmd` to add folders or replace the
complete mount list. Existing mappings are shown before changes are made.

Keep existing folders in the same order when replacing the list. The order
determines the stable container paths used by the catalog. After changing the
mounts, stop and start Sonotheque, then add new `/music/root-N` paths from the
in-app folder browser. Removing a mount does not immediately delete its catalog
entry; remove or rescan the corresponding library root from Settings.

## Later Starts And Stops

- Double-click `Start Sonotheque.cmd` to start or update the containers and
  open the app.
- Double-click `Stop Sonotheque.cmd` to stop the app without deleting data.
- Double-click `Sonotheque Status.cmd` to inspect the running services and
  show the current URL.
- Double-click `Configure Sonotheque Folders.cmd` to manage host-folder mounts.
- Double-click `Configure Sonotheque Audio Intelligence.cmd` only when the
  optional local analyzer should be provisioned.

Linux and macOS use the matching terminal commands:

```sh
./sonotheque start
./sonotheque status
./sonotheque open
./sonotheque stop
./sonotheque folders "/mnt/music" "/media/archive"
./sonotheque backup
./sonotheque intelligence --model "/opt/models/discogs-effnet.pb" --accelerator cpu
```

The `folders` command replaces the complete mount list. Keep existing folders
in the same order when changing it. Run `./sonotheque folders` without paths to
use a native picker where available.

The installation configuration is stored in `.env.packaged`. Application data
is stored in named Docker volumes. Do not delete either when updating.

## Backups

Create a backup before an update or a restore:

```powershell
.\scripts\backup.ps1 -Mode Packaged
```

Restore a backup only after reading the restore guidance in
`docs/setup-and-distribution.md`:

```powershell
.\scripts\restore.ps1 -BackupPath ".\backups\sonotheque-packaged-..." -Mode Packaged -Force
```

Backups contain application data, not the original music files.

Linux and macOS create the same portable, checksummed bundle format:

```sh
./sonotheque backup
./sonotheque restore "backups/sonotheque-packaged-..." --force
```

Restore makes a safety backup first and restarts the app only if it was running
beforehand. When moving a backup between installations, add
`--use-backup-app-key` after confirming that its backed-up encryption key should
replace the current one. Use `--skip-safety-backup` only when a separate current
backup already exists.

## LAN Access

Localhost mode is the default. To expose the app manually to the local network,
open PowerShell in the installation folder and run:

```powershell
.\scripts\start-packaged.ps1 -Lan -LanAddress 192.168.1.10
```

Replace the address with a private IPv4 address assigned to this computer.
The command prints the admin token and a narrow Windows Firewall command when
one is needed. LAN mode is never enabled automatically.

On Linux or macOS, pass the private IPv4 address explicitly:

```sh
./sonotheque start --lan 192.168.1.10
```

The launcher prints the admin token. Allow port `8080` only on the private
network using the host operating system's firewall tools; Sonotheque does not
change firewall rules automatically.

## Optional Audio Intelligence

Audio Intelligence is disabled by default and is not part of ordinary
first-run setup. Obtain and review the required model separately, then run
`Configure Sonotheque Audio Intelligence.cmd`. CPU is the portable default;
CUDA is an explicit PowerShell option for compatible NVIDIA systems. On Linux
or macOS use:

```sh
./sonotheque intelligence --model "/path/to/model.pb" --accelerator cpu
./sonotheque intelligence --disable
```

Omit `--model` to use the host picker. CUDA can be selected on a compatible
Linux NVIDIA setup; it is rejected on macOS rather than silently falling back.
Read
[`docs/audio-intelligence.md`](docs/audio-intelligence.md) for setup, resource,
privacy, licensing, and troubleshooting guidance.

Platform and architecture support levels are documented in
[`docs/platform-support.md`](docs/platform-support.md).

## Troubleshooting

- If Docker cannot be reached, start Docker Desktop and retry.
- If an upgrade cannot create the `vector` extension, run the one-time database
  administrator command documented under Optional Audio Intelligence in
  `docs/runtime.md`, then start Sonotheque again.
- If port `8080` is occupied, change `APP_HTTP_PORT` in `.env.packaged`.
- Use Settings > System after startup to inspect database, queue, scheduler,
  storage, library-root, and scan health.
- Keep the installation path and `.env.packaged` stable so encrypted settings
  and Docker volume references remain available.

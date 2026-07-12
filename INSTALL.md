# Sonotheque - Windows Installation

## Requirements

- Windows 10 or Windows 11
- Docker Desktop with Linux containers enabled
- Enough free disk space for Docker images, PostgreSQL data, and generated
  thumbnails

PHP, Composer, Node.js, PostgreSQL, and nginx do not need to be installed on
Windows. They are included in the Docker package.

## First Start

1. Start Docker Desktop and wait until it reports that the engine is running.
2. Extract the release ZIP into a permanent folder, for example
   `C:\MusicLibrary`. Do not run it directly from the ZIP or a temporary folder.
3. Double-click `Start Sonotheque.cmd`.
4. Select the host folder containing your music when prompted.
5. Wait for the first Docker build to finish. This takes longer than later
   starts because the application images must be downloaded and built.
6. The browser opens `http://127.0.0.1:8080/setup` automatically.
7. Complete the setup wizard, review metadata-writing options, and start the
   first library scan.

The selected host folder is mounted into the application as `/music/root-1`.
Music remains in its original location and is not copied into the package.

## Later Starts And Stops

- Double-click `Start Sonotheque.cmd` to start or update the containers and
  open the app.
- Double-click `Stop Sonotheque.cmd` to stop the app without deleting data.
- Double-click `Sonotheque Status.cmd` to inspect the running services and
  show the current URL.

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

## LAN Access

Localhost mode is the default. To expose the app manually to the local network,
open PowerShell in the installation folder and run:

```powershell
.\scripts\start-packaged.ps1 -Lan -LanAddress 192.168.1.10
```

Replace the address with a private IPv4 address assigned to this computer.
The command prints the admin token and a narrow Windows Firewall command when
one is needed. LAN mode is never enabled automatically.

## Troubleshooting

- If Docker cannot be reached, start Docker Desktop and retry.
- If port `8080` is occupied, change `APP_HTTP_PORT` in `.env.packaged`.
- Use Settings > System after startup to inspect database, queue, scheduler,
  storage, library-root, and scan health.
- Keep the installation path and `.env.packaged` stable so encrypted settings
  and Docker volume references remain available.

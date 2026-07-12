[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$BackupPath,

    [ValidateSet('Development', 'Packaged')]
    [string]$Mode = 'Development',

    [switch]$Force,
    [switch]$UseBackupAppKey,
    [switch]$SkipSafetyBackup,
    [switch]$NoRestart
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'backup-common.ps1')

function Restore-Database {
    param(
        [Parameter(Mandatory)][object]$Configuration,
        [Parameter(Mandatory)][string]$DumpPath
    )

    $container = Get-BackupPostgresContainer -Mode $Configuration.Mode
    $containerPath = '/tmp/music-library-system-restore.dump'
    Invoke-BackupChecked -FilePath 'docker' -ArgumentList @('cp', $DumpPath, "${container}:$containerPath")
    try {
        Invoke-BackupChecked -FilePath 'docker' -ArgumentList @(
            'exec', $container, 'pg_restore', '--list', $containerPath
        ) -DiscardOutput
        Invoke-BackupChecked -FilePath 'docker' -ArgumentList @(
            'exec', '-e', "PGPASSWORD=$($Configuration.Password)", $container,
            'pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges',
            '-U', $Configuration.User, '-d', $Configuration.Database, $containerPath
        )
    }
    finally {
        & docker exec $container rm -f $containerPath *> $null
    }
}

function Restore-DevelopmentStorage {
    param([Parameter(Mandatory)][string]$ArchivePath)

    $storageRoot = (Resolve-Path -LiteralPath (Join-Path $script:BackendDirectory 'storage')).Path
    $appPath = Join-Path $storageRoot 'app'
    $stagingPath = Join-Path $storageRoot ('.system-restore-' + [guid]::NewGuid().ToString('N'))
    $previousPath = Join-Path $storageRoot ('.system-restore-previous-' + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $stagingPath | Out-Null

    try {
        Invoke-BackupChecked -FilePath 'tar.exe' -ArgumentList @('-xf', $ArchivePath, '-C', $stagingPath)
        $restoredApp = Join-Path $stagingPath 'app'
        if (-not (Test-Path -LiteralPath $restoredApp -PathType Container)) {
            throw 'The development storage archive does not contain an app directory.'
        }

        if (Test-Path -LiteralPath $appPath) {
            Move-Item -LiteralPath $appPath -Destination $previousPath
        }
        try {
            Move-Item -LiteralPath $restoredApp -Destination $appPath
        }
        catch {
            if (Test-Path -LiteralPath $previousPath) {
                Move-Item -LiteralPath $previousPath -Destination $appPath
            }
            throw
        }

        if (Test-Path -LiteralPath $previousPath) {
            Remove-Item -LiteralPath $previousPath -Recurse -Force
        }
    }
    finally {
        if (Test-Path -LiteralPath $stagingPath) {
            Remove-Item -LiteralPath $stagingPath -Recurse -Force
        }
    }
}

function Restore-PackagedStorage {
    param([Parameter(Mandatory)][string]$BundlePath)

    $mount = ((Resolve-Path -LiteralPath $BundlePath).Path + ':/backup:ro')
    $command = 'rm -rf /app/storage/* /app/storage/.[!.]* /app/storage/..?* && tar -xf /backup/storage.tar -C /app/storage'
    Invoke-PackagedCompose -Arguments @(
        'run', '--rm', '--no-deps', '--volume', $mount,
        'backend', 'sh', '-c', $command
    )
}

try {
    if (-not $Force) {
        throw 'Restore replaces the current database and application storage. Re-run with -Force after verifying the backup path.'
    }

    $bundle = Assert-SystemBackupBundle -BundlePath $BackupPath
    if ([string]$bundle.Manifest.mode -ne $Mode) {
        throw "This is a $($bundle.Manifest.mode) backup, but restore mode is $Mode."
    }

    $configuration = Get-SystemBackupConfiguration -Mode $Mode
    $backupAppKey = (Get-Content -LiteralPath (Join-Path $bundle.Path 'app-key.txt') -Raw).Trim()
    $appKeyDiffers = $backupAppKey -ne $configuration.AppKey
    if ($appKeyDiffers -and -not $UseBackupAppKey) {
        throw 'The backup APP_KEY differs from this installation. Re-run with -UseBackupAppKey to restore encrypted settings.'
    }

    if (-not $SkipSafetyBackup) {
        Write-Host 'Creating a safety backup of the current installation...'
        $safetyDirectory = Join-Path $script:DefaultBackupDirectory 'pre-restore'
        & (Join-Path $PSScriptRoot 'backup.ps1') -Mode $Mode -Destination $safetyDirectory
        if ($LASTEXITCODE -ne 0) {
            throw 'The safety backup failed. Restore was not started.'
        }
    }

    $developmentRuntime = if ($Mode -eq 'Development') { Get-RuntimeModeState } else { $null }
    $packagedWasRunning = $false
    if ($Mode -eq 'Packaged') {
        Push-Location $script:RepositoryRoot
        try {
            $runningWeb = & docker compose --env-file $script:PackagedEnvironmentPath -f $script:PackagedComposeFile ps --status running -q web
            $packagedWasRunning = -not [string]::IsNullOrWhiteSpace([string]($runningWeb | Select-Object -First 1))
        }
        finally {
            Pop-Location
        }
    }

    Start-BackupPostgres -Mode $Mode
    Write-Host 'Stopping application services before restore...'
    if ($Mode -eq 'Development') {
        & (Join-Path $PSScriptRoot 'stop.ps1') -KeepDatabase
        if ($LASTEXITCODE -ne 0) {
            throw 'Development services could not be stopped.'
        }
    }
    else {
        Invoke-PackagedCompose -Arguments @('stop', 'web', 'backend', 'queue', 'scheduler')
    }

    if ($appKeyDiffers) {
        Set-BackupEnvValue -Path $configuration.EnvironmentPath -Name 'APP_KEY' -Value $backupAppKey
        $configuration.AppKey = $backupAppKey
    }

    Write-Host 'Restoring PostgreSQL database...'
    Restore-Database -Configuration $configuration -DumpPath (Join-Path $bundle.Path 'database.dump')

    Write-Host 'Restoring application storage...'
    if ($Mode -eq 'Development') {
        Restore-DevelopmentStorage -ArchivePath (Join-Path $bundle.Path 'storage.tar')
    }
    else {
        Restore-PackagedStorage -BundlePath $bundle.Path
    }

    Write-Host 'Running database migrations...'
    if ($Mode -eq 'Development') {
        $php = Resolve-Php85
        Push-Location $script:BackendDirectory
        try {
            Invoke-BackupChecked -FilePath $php -ArgumentList @('artisan', 'migrate', '--force')
        }
        finally {
            Pop-Location
        }
    }
    else {
        Invoke-PackagedCompose -Arguments @('run', '--rm', '--no-deps', 'backend', 'php', 'artisan', 'migrate', '--force')
    }

    if (-not $NoRestart) {
        if ($Mode -eq 'Development' -and $null -ne $developmentRuntime) {
            $arguments = @{}
            if ($developmentRuntime.mode -eq 'lan') {
                $arguments.Lan = $true
                $arguments.LanAddress = [string]$developmentRuntime.frontendHost
            }
            & (Join-Path $PSScriptRoot 'start.ps1') @arguments
        }
        elseif ($Mode -eq 'Packaged' -and $packagedWasRunning) {
            Invoke-PackagedCompose -Arguments @('up', '-d', 'backend', 'queue', 'scheduler', 'web')
        }
    }

    Write-SystemBackupMarker -Mode $Mode -Marker @{
        operation = 'restore'
        status = 'completed'
        mode = $Mode
        completedAt = (Get-Date).ToUniversalTime().ToString('o')
        bundleName = (Split-Path -Leaf $bundle.Path)
    }

    Write-Host ''
    Write-Host "Restore complete: $($bundle.Path)"
}
catch {
    Write-Error $_
    exit 1
}

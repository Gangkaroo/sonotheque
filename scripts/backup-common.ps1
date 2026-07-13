Set-StrictMode -Version Latest

. (Join-Path $PSScriptRoot 'runtime-common.ps1')
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

$script:SystemBackupVersion = 1
$script:DefaultBackupDirectory = Join-Path $script:RepositoryRoot 'backups'

function Invoke-BackupChecked {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$ArgumentList,
        [switch]$DiscardOutput
    )

    if ($DiscardOutput) {
        & $FilePath @ArgumentList | Out-Null
    }
    else {
        & $FilePath @ArgumentList
    }
    if ($LASTEXITCODE -ne 0) {
        throw "$FilePath exited with code $LASTEXITCODE."
    }
}

function Get-SystemBackupConfiguration {
    param([Parameter(Mandatory)][ValidateSet('Development', 'Packaged')][string]$Mode)

    if ($Mode -eq 'Development') {
        $environmentPath = Join-Path $script:BackendDirectory '.env'
        if (-not (Test-Path -LiteralPath $environmentPath)) {
            throw 'backend/.env does not exist.'
        }

        return [pscustomobject]@{
            Mode = $Mode
            EnvironmentPath = $environmentPath
            Database = (Get-BackupEnvValue -Path $environmentPath -Name 'DB_DATABASE' -Default 'sonotheque')
            User = (Get-BackupEnvValue -Path $environmentPath -Name 'DB_USERNAME' -Default 'sonotheque')
            Password = (Get-BackupEnvValue -Path $environmentPath -Name 'DB_PASSWORD' -Default '')
            AppKey = (Get-BackupEnvValue -Path $environmentPath -Name 'APP_KEY' -Default '')
        }
    }

    if (-not (Test-Path -LiteralPath $script:PackagedEnvironmentPath)) {
        throw '.env.packaged does not exist. Start packaged mode once before backing it up.'
    }

    return [pscustomobject]@{
        Mode = $Mode
        EnvironmentPath = $script:PackagedEnvironmentPath
        Database = (Get-BackupEnvValue -Path $script:PackagedEnvironmentPath -Name 'POSTGRES_DB' -Default 'sonotheque')
        User = (Get-BackupEnvValue -Path $script:PackagedEnvironmentPath -Name 'POSTGRES_USER' -Default 'sonotheque')
        Password = (Get-BackupEnvValue -Path $script:PackagedEnvironmentPath -Name 'POSTGRES_PASSWORD' -Default '')
        AppKey = (Get-BackupEnvValue -Path $script:PackagedEnvironmentPath -Name 'APP_KEY' -Default '')
    }
}

function Get-BackupEnvValue {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][AllowEmptyString()][string]$Default
    )

    $value = Get-PackagedEnvValue -Path $Path -Name $Name
    if ([string]::IsNullOrWhiteSpace($value)) {
        return $Default
    }

    return $value
}

function Set-BackupEnvValue {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][AllowEmptyString()][string]$Value
    )

    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($line in Get-Content -LiteralPath $Path) {
        $lines.Add($line)
    }

    $pattern = '^\s*' + [regex]::Escape($Name) + '\s*='
    $replacement = "${Name}=${Value}"
    $updated = $false
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match $pattern) {
            $lines[$index] = $replacement
            $updated = $true
            break
        }
    }

    if (-not $updated) {
        $lines.Add($replacement)
    }

    Set-Content -LiteralPath $Path -Value $lines -Encoding utf8
}

function Start-BackupPostgres {
    param([Parameter(Mandatory)][ValidateSet('Development', 'Packaged')][string]$Mode)

    Assert-PackagedDockerAvailable
    if ($Mode -eq 'Development') {
        Invoke-DockerCompose -Arguments @('up', '-d', 'postgres')
    }
    else {
        Invoke-PackagedCompose -Arguments @('up', '-d', 'postgres')
    }
}

function Get-BackupPostgresContainer {
    param([Parameter(Mandatory)][ValidateSet('Development', 'Packaged')][string]$Mode)

    Push-Location $script:RepositoryRoot
    try {
        $container = if ($Mode -eq 'Development') {
            & docker compose -f compose.yaml ps -q postgres
        }
        else {
            & docker compose --env-file $script:PackagedEnvironmentPath -f $script:PackagedComposeFile ps -q postgres
        }
        if ($LASTEXITCODE -ne 0) {
            throw 'Docker Compose could not locate PostgreSQL.'
        }
    }
    finally {
        Pop-Location
    }

    $containerId = [string]($container | Select-Object -First 1)
    if ([string]::IsNullOrWhiteSpace($containerId)) {
        throw "$Mode PostgreSQL container was not found."
    }

    return $containerId.Trim()
}

function Export-BackupDatabase {
    param(
        [Parameter(Mandatory)][object]$Configuration,
        [Parameter(Mandatory)][string]$Destination
    )

    $container = Get-BackupPostgresContainer -Mode $Configuration.Mode
    $containerPath = '/tmp/sonotheque-system-backup.dump'
    Invoke-BackupChecked -FilePath 'docker' -ArgumentList @(
        'exec', '-e', "PGPASSWORD=$($Configuration.Password)", $container,
        'pg_dump', '-U', $Configuration.User, '-d', $Configuration.Database,
        '--format=custom', '--file', $containerPath
    )
    try {
        Invoke-BackupChecked -FilePath 'docker' -ArgumentList @('cp', "${container}:$containerPath", $Destination)
        Invoke-BackupChecked -FilePath 'docker' -ArgumentList @(
            'exec', $container, 'pg_restore', '--list', $containerPath
        ) -DiscardOutput
    }
    finally {
        & docker exec $container rm -f $containerPath *> $null
    }
}

function Export-BackupStorage {
    param(
        [Parameter(Mandatory)][ValidateSet('Development', 'Packaged')][string]$Mode,
        [Parameter(Mandatory)][string]$BundlePath
    )

    $archivePath = Join-Path $BundlePath 'storage.tar'
    if ($Mode -eq 'Development') {
        $tarCommand = Get-Command tar.exe -ErrorAction SilentlyContinue
        if ($null -eq $tarCommand) {
            throw 'tar.exe was not found on PATH.'
        }
        $tar = $tarCommand.Source

        Invoke-BackupChecked -FilePath $tar -ArgumentList @(
            '-cf', $archivePath,
            '-C', (Join-Path $script:BackendDirectory 'storage'),
            'app'
        )
        return
    }

    $mount = ((Resolve-Path -LiteralPath $BundlePath).Path + ':/backup')
    Invoke-PackagedCompose -Arguments @(
        'run', '--rm', '--no-deps', '--volume', $mount,
        'backend', 'sh', '-c', 'tar -cf /backup/storage.tar -C /app/storage .'
    )
}

function Get-BackupFileDescriptor {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Name
    )

    $file = Get-Item -LiteralPath $Path
    return [ordered]@{
        name = $Name
        bytes = $file.Length
        sha256 = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
    }
}

function Write-SystemBackupMarker {
    param(
        [Parameter(Mandatory)][ValidateSet('Development', 'Packaged')][string]$Mode,
        [Parameter(Mandatory)][hashtable]$Marker
    )

    $json = $Marker | ConvertTo-Json -Depth 5
    if ($Mode -eq 'Development') {
        $directory = Join-Path $script:BackendDirectory 'storage\app\system-backups'
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $directory 'latest.json') -Value $json -Encoding utf8
        return
    }

    try {
        $encoded = [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($json))
        $php = "`$p='/app/storage/app/system-backups/latest.json';@mkdir(dirname(`$p),0777,true);file_put_contents(`$p,base64_decode('$encoded'));"
        Invoke-PackagedCompose -Arguments @('exec', '-T', 'backend', 'php', '-r', $php)
    }
    catch {
        Write-Warning 'The backup succeeded, but packaged backup status could not be written because the backend is not running.'
    }
}

function Assert-SystemBackupBundle {
    param([Parameter(Mandatory)][string]$BundlePath)

    $resolved = (Resolve-Path -LiteralPath $BundlePath).Path
    $manifestPath = Join-Path $resolved 'manifest.json'
    if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
        throw 'The backup bundle does not contain manifest.json.'
    }

    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    if ([int]$manifest.version -ne $script:SystemBackupVersion) {
        throw "Unsupported backup bundle version: $($manifest.version)"
    }

    foreach ($descriptor in $manifest.files) {
        $filePath = Join-Path $resolved $descriptor.name
        if (-not (Test-Path -LiteralPath $filePath -PathType Leaf)) {
            throw "Backup file is missing: $($descriptor.name)"
        }

        $hash = (Get-FileHash -LiteralPath $filePath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($hash -ne [string]$descriptor.sha256) {
            throw "Backup checksum is invalid: $($descriptor.name)"
        }
    }

    return [pscustomobject]@{
        Path = $resolved
        Manifest = $manifest
    }
}

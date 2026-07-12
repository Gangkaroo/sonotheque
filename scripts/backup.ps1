[CmdletBinding()]
param(
    [ValidateSet('Development', 'Packaged')]
    [string]$Mode = 'Development',

    [string]$Destination
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'backup-common.ps1')

$bundlePath = $null

try {
    $configuration = Get-SystemBackupConfiguration -Mode $Mode
    if ([string]::IsNullOrWhiteSpace($configuration.AppKey)) {
        throw "$Mode APP_KEY is missing. Encrypted settings could not be restored without it."
    }

    $destinationRoot = if ([string]::IsNullOrWhiteSpace($Destination)) {
        $script:DefaultBackupDirectory
    }
    else {
        $Destination
    }
    New-Item -ItemType Directory -Path $destinationRoot -Force | Out-Null
    $destinationRoot = (Resolve-Path -LiteralPath $destinationRoot).Path

    $timestamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
    $bundleName = "music-library-$($Mode.ToLowerInvariant())-$timestamp"
    $bundlePath = Join-Path $destinationRoot $bundleName
    New-Item -ItemType Directory -Path $bundlePath | Out-Null

    Write-Host "Creating $Mode backup..."
    Start-BackupPostgres -Mode $Mode

    $databasePath = Join-Path $bundlePath 'database.dump'
    Write-Host 'Exporting PostgreSQL database...'
    Export-BackupDatabase -Configuration $configuration -Destination $databasePath

    Write-Host 'Archiving application storage...'
    Export-BackupStorage -Mode $Mode -BundlePath $bundlePath

    $appKeyPath = Join-Path $bundlePath 'app-key.txt'
    Set-Content -LiteralPath $appKeyPath -Value $configuration.AppKey -NoNewline -Encoding utf8

    $files = @(
        Get-BackupFileDescriptor -Path $databasePath -Name 'database.dump'
        Get-BackupFileDescriptor -Path (Join-Path $bundlePath 'storage.tar') -Name 'storage.tar'
        Get-BackupFileDescriptor -Path $appKeyPath -Name 'app-key.txt'
    )
    $manifest = [ordered]@{
        version = $script:SystemBackupVersion
        createdAt = (Get-Date).ToUniversalTime().ToString('o')
        mode = $Mode
        database = $configuration.Database
        files = $files
    }
    $manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $bundlePath 'manifest.json') -Encoding utf8

    $totalBytes = ($files | Measure-Object -Property bytes -Sum).Sum
    Write-SystemBackupMarker -Mode $Mode -Marker @{
        operation = 'backup'
        status = 'completed'
        mode = $Mode
        completedAt = (Get-Date).ToUniversalTime().ToString('o')
        bundleName = $bundleName
        bytes = $totalBytes
    }

    Write-Host ''
    Write-Host "Backup complete: $bundlePath"
    Write-Warning 'The bundle contains the Laravel APP_KEY. Store it securely together with the database dump.'
}
catch {
    if ($null -ne $bundlePath -and (Test-Path -LiteralPath $bundlePath)) {
        Remove-Item -LiteralPath $bundlePath -Recurse -Force
    }
    Write-Error $_
    exit 1
}

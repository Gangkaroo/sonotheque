[CmdletBinding()]
param(
    [string]$Version
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = (Get-Content -LiteralPath (Join-Path $repositoryRoot 'VERSION') -Raw).Trim()
}

$archiveName = "sonotheque-$Version-windows-portable"
$releaseDirectory = Join-Path $repositoryRoot 'dist\releases'
$archivePath = Join-Path $releaseDirectory "$archiveName.zip"
$checksumPath = "$archivePath.sha256"
if (-not (Test-Path -LiteralPath $archivePath -PathType Leaf)) {
    throw "Release archive does not exist: $archivePath"
}
if (-not (Test-Path -LiteralPath $checksumPath -PathType Leaf)) {
    throw "Release checksum does not exist: $checksumPath"
}

$checksumParts = (Get-Content -LiteralPath $checksumPath -Raw).Trim() -split '\s+', 2
$actualHash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
if ($checksumParts.Count -ne 2 -or
    $checksumParts[0].ToLowerInvariant() -ne $actualHash -or
    $checksumParts[1] -ne [System.IO.Path]::GetFileName($archivePath)) {
    throw 'Release checksum file does not match the portable archive.'
}

Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [System.IO.Compression.ZipFile]::OpenRead($archivePath)
try {
    $entries = @($archive.Entries | ForEach-Object { $_.FullName -replace '\\', '/' })
    $prefix = "$archiveName/"
    $required = @(
        "${prefix}Start Sonotheque.cmd",
        "${prefix}Stop Sonotheque.cmd",
        "${prefix}Sonotheque Status.cmd",
        "${prefix}Configure Sonotheque Folders.cmd",
        "${prefix}Configure Sonotheque Audio Intelligence.cmd",
        "${prefix}sonotheque",
        "${prefix}INSTALL.md",
        "${prefix}docs/audio-intelligence.md",
        "${prefix}docs/platform-support.md",
        "${prefix}compose.packaged.yaml",
        "${prefix}backend/Dockerfile.packaged",
        "${prefix}frontend/Dockerfile.packaged",
        "${prefix}scripts/verify-lan.ps1",
        "${prefix}scripts/packaged-config.php",
        "${prefix}scripts/lib/PackagedConfiguration.php",
        "${prefix}scripts/system-backup-bundle.php",
        "${prefix}scripts/lib/SystemBackupBundle.php"
    )
    $missing = @($required | Where-Object { $_ -notin $entries })
    $outsidePrefix = @($entries | Where-Object { -not $_.StartsWith($prefix) })
    $forbidden = @($entries | Where-Object {
        $_ -match '(^|/)\.git/' -or
        $_ -match '(^|/)\.env\.packaged$' -or
        $_ -match '(^|/)compose\.packaged\.override\.yaml$' -or
        $_ -match '(^|/)packaged-roots\.json$' -or
        $_ -match '(^|/)node_modules/' -or
        $_ -match '(^|/)vendor/' -or
        $_ -match '(^|/)backups/' -or
        $_ -match '(^|/)runtime-logs/' -or
        $_ -match '(^|/)AGENTS\.md$'
    })
    if ($missing.Count -gt 0) {
        throw "Release archive is missing required entries: $($missing -join ', ')"
    }
    if ($outsidePrefix.Count -gt 0) {
        throw "Release archive contains entries outside its top-level folder: $($outsidePrefix -join ', ')"
    }
    if ($forbidden.Count -gt 0) {
        throw "Release archive contains forbidden entries: $($forbidden -join ', ')"
    }
} finally {
    $archive.Dispose()
}

Write-Host "Release verified: $archivePath"
Write-Host "SHA-256: $actualHash"

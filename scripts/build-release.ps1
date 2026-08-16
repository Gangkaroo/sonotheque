[CmdletBinding()]
param(
    [string]$Version,
    [switch]$KeepStagingDirectory
)

$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$versionPath = Join-Path $repositoryRoot 'VERSION'
$outputRoot = Join-Path $repositoryRoot 'dist\releases'

if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = (Get-Content -LiteralPath $versionPath -Raw).Trim()
}

if ($Version -notmatch '^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$') {
    throw "Version '$Version' is not a valid semantic version."
}

$archiveName = "sonotheque-$Version-windows-portable"
$stagingRoot = Join-Path $outputRoot $archiveName
$archivePath = Join-Path $outputRoot "$archiveName.zip"
$checksumPath = "$archivePath.sha256"

$excludedPrefixes = @(
    '.agents/',
    '.codex/',
    '.git/',
    '.github/',
    '.idea/',
    'backups/',
    'dist/',
    'runtime-logs/'
)
$excludedFiles = @(
    '.env.packaged',
    'AGENTS.md'
)
$releaseSupportFiles = @(
    'CHANGELOG.md',
    'Configure Sonotheque Audio Intelligence.cmd',
    'Configure Sonotheque Folders.cmd',
    'INSTALL.md',
    'Sonotheque Status.cmd',
    'Start Sonotheque.cmd',
    'Stop Sonotheque.cmd',
    'sonotheque',
    'VERSION',
    'docs/platform-support.md',
    'scripts/build-release.ps1',
    'scripts/configure-packaged-audio-intelligence.ps1',
    'scripts/configure-packaged-roots.ps1',
    'scripts/launch-packaged.ps1',
    'scripts/packaged-config.php',
    'scripts/lib/PackagedConfiguration.php',
    'scripts/system-backup-bundle.php',
    'scripts/lib/SystemBackupBundle.php',
    'scripts/verify-lan.ps1'
)

New-Item -ItemType Directory -Path $outputRoot -Force | Out-Null
if (Test-Path -LiteralPath $stagingRoot) {
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}
if (Test-Path -LiteralPath $archivePath) {
    Remove-Item -LiteralPath $archivePath -Force
}
if (Test-Path -LiteralPath $checksumPath) {
    Remove-Item -LiteralPath $checksumPath -Force
}
New-Item -ItemType Directory -Path $stagingRoot -Force | Out-Null

Push-Location $repositoryRoot
try {
    $trackedFiles = @(& git ls-files)
    if ($LASTEXITCODE -ne 0) {
        throw 'Unable to enumerate repository files with git.'
    }

    $releaseFiles = @($trackedFiles + $releaseSupportFiles | Select-Object -Unique)
    foreach ($relativePath in $releaseFiles) {
        $normalizedPath = $relativePath.Replace('\', '/')
        if ($normalizedPath -in $excludedFiles) {
            continue
        }
        if (@($excludedPrefixes | Where-Object { $normalizedPath.StartsWith($_) }).Count -gt 0) {
            continue
        }

        $sourcePath = Join-Path $repositoryRoot $relativePath
        if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
            continue
        }

        $destinationPath = Join-Path $stagingRoot $relativePath
        $destinationDirectory = Split-Path -Parent $destinationPath
        New-Item -ItemType Directory -Path $destinationDirectory -Force | Out-Null
        Copy-Item -LiteralPath $sourcePath -Destination $destinationPath
    }
}
finally {
    Pop-Location
}

Compress-Archive -LiteralPath $stagingRoot -DestinationPath $archivePath -CompressionLevel Optimal
$hash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -LiteralPath $checksumPath -Value "$hash  $([System.IO.Path]::GetFileName($archivePath))" -Encoding ascii

if (-not $KeepStagingDirectory) {
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

Write-Host "Created release archive: $archivePath"
Write-Host "Created SHA-256 file:  $checksumPath"

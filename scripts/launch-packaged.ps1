[CmdletBinding()]
param(
    [switch]$NoBuild,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

function Select-MusicLibraryFolder {
    Add-Type -AssemblyName System.Windows.Forms

    $dialog = New-Object System.Windows.Forms.FolderBrowserDialog
    $dialog.Description = 'Select the folder containing your music library.'
    $dialog.ShowNewFolderButton = $false

    try {
        if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
            throw 'No music folder was selected. Setup was cancelled.'
        }

        return $dialog.SelectedPath
    }
    finally {
        $dialog.Dispose()
    }
}

try {
    $musicRoot = $null
    $configuredRoot = Get-PackagedEnvValue -Name 'MUSIC_LIBRARY_ROOT_1'
    $isFirstStart = [string]::IsNullOrWhiteSpace($configuredRoot) -or
        $configuredRoot -eq './packaged/music-root-1'
    if ($isFirstStart) {
        $musicRoot = Select-MusicLibraryFolder
    }

    $startArguments = @{}
    if (-not [string]::IsNullOrWhiteSpace($musicRoot)) {
        $startArguments.MusicRoot = $musicRoot
    }
    if ($NoBuild) {
        $startArguments.NoBuild = $true
    }

    & (Join-Path $PSScriptRoot 'start-packaged.ps1') @startArguments
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }

    $appUrl = Get-PackagedEnvValue -Name 'APP_URL'
    if ([string]::IsNullOrWhiteSpace($appUrl)) {
        $appUrl = 'http://127.0.0.1:8080'
    }

    if (-not $NoBrowser) {
        $browserPath = if ($isFirstStart) { '/setup' } else { '/' }
        Start-Process ($appUrl.TrimEnd('/') + $browserPath)
    }
}
catch {
    Write-Error $_
    exit 1
}

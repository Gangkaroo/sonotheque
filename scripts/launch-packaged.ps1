[CmdletBinding()]
param(
    [switch]$NoBuild,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

try {
    $isFirstStart = @(Get-PackagedMusicRoots).Count -eq 0
    if ($isFirstStart) {
        & (Join-Path $PSScriptRoot 'configure-packaged-roots.ps1') -Replace
    }

    $startArguments = @{}
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

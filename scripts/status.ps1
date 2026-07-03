[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'runtime-common.ps1')

try {
    Initialize-RuntimeDirectory
    $status = Get-RuntimeStatus
    $status | Format-Table -AutoSize

    $runtimeMode = Get-RuntimeModeState
    if ($null -ne $runtimeMode) {
        Write-Host "Runtime mode: $($runtimeMode.mode)"
        Write-Host "App URL: $($runtimeMode.frontendUrl)"
    }
    else {
        Write-Host 'Runtime mode: unknown (start the app with scripts/start.ps1)'
    }

    $healthy = @($status | Where-Object {
        $_.Status -notin @('Healthy', 'Running')
    }).Count -eq 0

    if (-not $healthy) {
        exit 1
    }
}
catch {
    Write-Error $_
    exit 1
}

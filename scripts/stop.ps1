[CmdletBinding()]
param(
    [switch]$KeepDatabase
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'runtime-common.ps1')

try {
    foreach ($service in @('frontend', 'scheduler', 'queue-worker', 'api')) {
        if (Stop-ManagedProcess -Name $service) {
            Write-Host "Stopped managed service: $service"
        }
        else {
            Write-Host "Left $service unchanged because it was not started by scripts/start.ps1."
        }
    }

    Remove-RuntimeModeState
    Stop-AudioAnalyzerContainers

    if ($KeepDatabase) {
        Write-Host 'PostgreSQL left running because -KeepDatabase was specified.'
    }
    else {
        Write-Host 'Stopping PostgreSQL...'
        Invoke-DockerCompose -Arguments @('stop', 'postgres')
    }

    Write-Host ''
    Get-RuntimeStatus | Format-Table -AutoSize
}
catch {
    Write-Error $_
    exit 1
}

[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'runtime-common.ps1')

try {
    Initialize-RuntimeDirectory
    $status = Get-RuntimeStatus
    $status | Format-Table -AutoSize

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

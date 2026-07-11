[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

try {
    Assert-PackagedDockerAvailable
    Invoke-PackagedCompose -Arguments @('down') -AllowExampleEnvironment
}
catch {
    Write-Error $_
    exit 1
}

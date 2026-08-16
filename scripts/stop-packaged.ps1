[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

try {
    Assert-PackagedDockerAvailable
    Invoke-PackagedCompose -Arguments @('--profile', 'audio-intelligence', 'down') -AllowExampleEnvironment
    $analyzerContainers = @(& docker ps -aq --filter 'label=sonotheque.audio-analyzer=true')
    if ($analyzerContainers.Count -gt 0) {
        & docker rm --force @analyzerContainers | Out-Null
    }
}
catch {
    Write-Error $_
    exit 1
}

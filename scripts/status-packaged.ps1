[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

try {
    Assert-PackagedDockerAvailable
    Invoke-PackagedCompose -Arguments @('ps') -AllowExampleEnvironment

    if (Test-Path -LiteralPath $script:PackagedEnvironmentPath) {
        $appUrl = Get-PackagedEnvValue -Name 'APP_URL'
        if (-not [string]::IsNullOrWhiteSpace($appUrl)) {
            Write-Host ''
            Write-Host "App URL: $appUrl/"
        }
    }
    else {
        Write-Host ''
        Write-Host 'No .env.packaged file exists yet. Run scripts/start-packaged.ps1 to initialize packaged mode.'
    }
}
catch {
    Write-Error $_
    exit 1
}

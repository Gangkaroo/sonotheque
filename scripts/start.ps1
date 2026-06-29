[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'runtime-common.ps1')

try {
    Initialize-RuntimeDirectory
    Assert-RuntimePrerequisites
    $php = Resolve-Php85
    $node = Resolve-Node

    Write-Host 'Starting PostgreSQL...'
    Invoke-DockerCompose -Arguments @('up', '-d', 'postgres')
    Wait-Postgres

    $apiOwner = Get-PortOwner -Port 8000
    if ($null -eq $apiOwner) {
        Write-Host 'Starting Laravel API...'
        Start-ManagedProcess `
            -Name 'api' `
            -FilePath $php `
            -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8000') `
            -WorkingDirectory $script:BackendDirectory `
            -StandardOutputPath (Join-Path $script:RuntimeLogDirectory 'backend-server.out.log') `
            -StandardErrorPath (Join-Path $script:RuntimeLogDirectory 'backend-server.err.log') | Out-Null
    }
    elseif (-not (Test-HttpEndpoint -Uri 'http://127.0.0.1:8000/api/dashboard-metrics')) {
        throw "Port 8000 is already in use by PID $apiOwner, but the API health check failed."
    }
    else {
        Write-Host "Laravel API is already available on port 8000 (external PID $apiOwner)."
    }

    if ($null -eq (Get-ManagedProcess -Name 'queue-worker') -and $null -eq (Find-ExternalQueueWorker)) {
        Write-Host 'Starting queue worker...'
        Start-ManagedProcess `
            -Name 'queue-worker' `
            -FilePath $php `
            -ArgumentList @('artisan', 'queue:listen', '--tries=1', '--timeout=1800', '--memory=512') `
            -WorkingDirectory $script:BackendDirectory `
            -StandardOutputPath (Join-Path $script:RuntimeLogDirectory 'queue-worker.out.log') `
            -StandardErrorPath (Join-Path $script:RuntimeLogDirectory 'queue-worker.err.log') | Out-Null
    }
    else {
        Write-Host 'Queue worker is already running.'
    }

    $frontendOwner = Get-PortOwner -Port 5173
    if ($null -eq $frontendOwner) {
        Write-Host 'Starting Vue frontend...'
        $viteScript = Join-Path $script:FrontendDirectory 'node_modules\vite\bin\vite.js'
        Start-ManagedProcess `
            -Name 'frontend' `
            -FilePath $node `
            -ArgumentList @("`"$viteScript`"", '--host', '127.0.0.1', '--port', '5173') `
            -WorkingDirectory $script:FrontendDirectory `
            -StandardOutputPath (Join-Path $script:RuntimeLogDirectory 'frontend-vite.out.log') `
            -StandardErrorPath (Join-Path $script:RuntimeLogDirectory 'frontend-vite.err.log') | Out-Null
    }
    elseif (-not (Test-HttpEndpoint -Uri 'http://127.0.0.1:5173/')) {
        throw "Port 5173 is already in use by PID $frontendOwner, but the frontend health check failed."
    }
    else {
        Write-Host "Vue frontend is already available on port 5173 (external PID $frontendOwner)."
    }

    Wait-HttpEndpoint -Name 'Laravel API' -Uri 'http://127.0.0.1:8000/api/dashboard-metrics'
    Wait-HttpEndpoint -Name 'Vue frontend' -Uri 'http://127.0.0.1:5173/'

    Write-Host ''
    Get-RuntimeStatus | Format-Table -AutoSize
    Write-Host 'Music Library is available at http://127.0.0.1:5173/'
}
catch {
    Write-Error $_
    exit 1
}

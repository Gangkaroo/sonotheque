[CmdletBinding()]
param(
    [switch]$Lan,
    [string]$LanAddress
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'runtime-common.ps1')
$startedServices = [System.Collections.Generic.List[string]]::new()

try {
    Initialize-RuntimeDirectory
    Assert-RuntimePrerequisites
    $php = Resolve-Php85
    $node = Resolve-Node

    if (-not $Lan -and -not [string]::IsNullOrWhiteSpace($LanAddress)) {
        throw '-LanAddress can only be used together with -Lan.'
    }

    $mode = if ($Lan) { 'lan' } else { 'local' }
    $frontendHost = if ($Lan) {
        Resolve-LanIpv4Address -RequestedAddress $LanAddress
    }
    else {
        '127.0.0.1'
    }
    $frontendUri = "http://${frontendHost}:5173/"
    $apiHealthUri = 'http://127.0.0.1:8000/up'
    $existingMode = Get-RuntimeModeState
    $apiOwner = Get-PortOwner -Port 8000
    $frontendOwner = Get-PortOwner -Port 5173

    if ($null -eq $apiOwner -and $null -eq $frontendOwner) {
        Remove-RuntimeModeState
        $existingMode = $null
    }
    elseif ($null -ne $existingMode -and $existingMode.mode -ne $mode) {
        throw "The app is already running in $($existingMode.mode) mode. Run scripts/stop.ps1 before switching to $mode mode."
    }
    elseif ($Lan -and $null -eq $existingMode) {
        throw 'Ports 8000 or 5173 are already in use by a runtime with an unknown mode. Stop it before starting LAN mode.'
    }

    $backendEnvironment = @{
        SONOTHEQUE_LAN_ENABLED = if ($Lan) { 'true' } else { 'false' }
        SONOTHEQUE_TRUSTED_HOSTS = 'localhost,127.0.0.1,::1'
    }
    $frontendEnvironment = @{}
    $adminToken = $null

    if ($Lan) {
        $adminToken = Get-LanAdminToken
        $trustedHosts = Get-LanTrustedHosts -LanAddress $frontendHost
        $backendEnvironment.SONOTHEQUE_ADMIN_TOKEN = $adminToken
        $backendEnvironment.SONOTHEQUE_TRUSTED_HOSTS = $trustedHosts -join ','
        $frontendEnvironment.SONOTHEQUE_VITE_ALLOWED_HOSTS = [System.Net.Dns]::GetHostName()

        Write-Host "Preparing explicit LAN mode on $frontendHost..."
    }

    $configurationCache = Join-Path $script:BackendDirectory 'bootstrap\cache\config.php'
    if ($null -eq $apiOwner -and (Test-Path -LiteralPath $configurationCache)) {
        Write-Host 'Clearing cached Laravel configuration for runtime-specific settings...'
        Push-Location $script:BackendDirectory
        try {
            & $php artisan config:clear --no-ansi
            if ($LASTEXITCODE -ne 0) {
                throw "Laravel config:clear exited with code $LASTEXITCODE."
            }
        }
        finally {
            Pop-Location
        }
    }

    Write-Host 'Starting PostgreSQL...'
    Invoke-DockerCompose -Arguments @('up', '-d', 'postgres')
    Wait-Postgres

    if ($null -eq $apiOwner) {
        Write-Host 'Starting Laravel API...'
        Start-ManagedProcess `
            -Name 'api' `
            -FilePath $php `
            -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8000') `
            -WorkingDirectory $script:BackendDirectory `
            -StandardOutputPath (Join-Path $script:RuntimeLogDirectory 'backend-server.out.log') `
            -StandardErrorPath (Join-Path $script:RuntimeLogDirectory 'backend-server.err.log') `
            -EnvironmentVariables $backendEnvironment | Out-Null
        $startedServices.Add('api')
    }
    elseif (-not (Test-HttpEndpoint -Uri $apiHealthUri)) {
        throw "Port 8000 is already in use by PID $apiOwner, but the API health check failed."
    }
    else {
        Write-Host "Laravel API is already available on port 8000 (external PID $apiOwner)."
    }

    foreach ($queueWorker in @(
        @{ Name = 'queue-default'; Queue = 'default'; Label = 'interactive' }
        @{ Name = 'queue-scans'; Queue = 'scans'; Label = 'library scan' }
        @{ Name = 'queue-analysis'; Queue = 'analysis'; Label = 'audio analysis' }
    )) {
        $managedQueueWorker = Get-ManagedProcess -Name $queueWorker.Name
        $externalQueueWorker = if ($null -eq $managedQueueWorker) {
            Find-ExternalQueueWorker -Queue $queueWorker.Queue
        }
        else {
            $null
        }

        if ($null -eq $managedQueueWorker -and $null -eq $externalQueueWorker) {
            Write-Host "Starting $($queueWorker.Label) queue worker..."
            Start-ManagedProcess `
                -Name $queueWorker.Name `
                -FilePath $php `
                -ArgumentList @('artisan', 'queue:listen', "--queue=$($queueWorker.Queue)", '--tries=1', '--timeout=0', '--memory=512', '--sleep=1') `
                -WorkingDirectory $script:BackendDirectory `
                -StandardOutputPath (Join-Path $script:RuntimeLogDirectory "$($queueWorker.Name).out.log") `
                -StandardErrorPath (Join-Path $script:RuntimeLogDirectory "$($queueWorker.Name).err.log") `
                -EnvironmentVariables $backendEnvironment | Out-Null
            $startedServices.Add($queueWorker.Name)
        }
        else {
            Write-Host "$($queueWorker.Label) queue worker is already running."
        }
    }

    if ($null -eq (Get-ManagedProcess -Name 'scheduler') -and $null -eq (Find-ExternalScheduler)) {
        Write-Host 'Starting scheduler...'
        Start-ManagedProcess `
            -Name 'scheduler' `
            -FilePath $php `
            -ArgumentList @('artisan', 'schedule:work') `
            -WorkingDirectory $script:BackendDirectory `
            -StandardOutputPath (Join-Path $script:RuntimeLogDirectory 'scheduler.out.log') `
            -StandardErrorPath (Join-Path $script:RuntimeLogDirectory 'scheduler.err.log') `
            -EnvironmentVariables $backendEnvironment | Out-Null
        $startedServices.Add('scheduler')
    }
    else {
        Write-Host 'Scheduler is already running.'
    }

    if ($null -eq $frontendOwner) {
        Write-Host "Starting Vue frontend on $frontendHost..."
        $viteScript = Join-Path $script:FrontendDirectory 'node_modules\vite\bin\vite.js'
        Start-ManagedProcess `
            -Name 'frontend' `
            -FilePath $node `
            -ArgumentList @("`"$viteScript`"", '--host', $frontendHost, '--port', '5173') `
            -WorkingDirectory $script:FrontendDirectory `
            -StandardOutputPath (Join-Path $script:RuntimeLogDirectory 'frontend-vite.out.log') `
            -StandardErrorPath (Join-Path $script:RuntimeLogDirectory 'frontend-vite.err.log') `
            -EnvironmentVariables $frontendEnvironment | Out-Null
        $startedServices.Add('frontend')
    }
    elseif (-not (Test-HttpEndpoint -Uri $frontendUri)) {
        throw "Port 5173 is already in use by PID $frontendOwner, but the frontend health check failed."
    }
    else {
        Write-Host "Vue frontend is already available on port 5173 (external PID $frontendOwner)."
    }

    Wait-HttpEndpoint -Name 'Laravel API' -Uri $apiHealthUri
    Wait-HttpEndpoint -Name 'Vue frontend' -Uri $frontendUri

    if ($Lan) {
        $accessUri = "${frontendUri}api/settings/access"
        $unauthorizedStatus = Get-HttpStatusCode -Uri $accessUri
        if ($unauthorizedStatus -ne 403) {
            throw "LAN authorization check failed: an unauthenticated request returned HTTP $unauthorizedStatus instead of 403."
        }

        $authorizedStatus = Get-HttpStatusCode `
            -Uri $accessUri `
            -Headers @{ 'X-Sonotheque-Admin-Token' = $adminToken }
        if ($authorizedStatus -ne 200) {
            throw "LAN authorization check failed: the configured admin token returned HTTP $authorizedStatus."
        }
    }

    Set-RuntimeModeState -Mode $mode -FrontendHost $frontendHost

    Write-Host ''
    Get-RuntimeStatus | Format-Table -AutoSize
    Write-Host "Sonotheque is available at $frontendUri"

    if ($Lan) {
        Write-Host ''
        Write-Host 'If another device cannot connect, allow only TCP 5173 for Private networks and LocalSubnet:'
        Write-Host "New-NetFirewallRule -DisplayName 'Sonotheque LAN' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 5173 -LocalAddress $frontendHost -RemoteAddress LocalSubnet -Profile Private"
        Write-Host 'Run that command once from an elevated PowerShell window.'
    }
}
catch {
    if ($Lan) {
        foreach ($service in @(
            'frontend',
            'scheduler',
            'queue-analysis',
            'queue-scans',
            'queue-default',
            'api'
        )) {
            if ($startedServices.Contains($service)) {
                Stop-ManagedProcess -Name $service | Out-Null
            }
        }
        Remove-RuntimeModeState
    }

    Write-Error $_
    exit 1
}

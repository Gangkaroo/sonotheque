[CmdletBinding()]
param(
    [string]$MusicRoot,
    [switch]$Lan,
    [string]$LanAddress,
    [switch]$NoBuild
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

try {
    if (-not $Lan -and -not [string]::IsNullOrWhiteSpace($LanAddress)) {
        throw '-LanAddress can only be used together with -Lan.'
    }

    Assert-PackagedDockerAvailable
    New-Item -ItemType Directory -Path (Join-Path $script:RepositoryRoot 'backups') -Force | Out-Null
    if (-not [string]::IsNullOrWhiteSpace($MusicRoot)) {
        if (Test-Path -LiteralPath $script:PackagedRootsPath) {
            throw '-MusicRoot cannot replace an existing generated root configuration. Run Configure Sonotheque Folders.cmd instead.'
        }
        Set-PackagedMusicRoots -MusicRoots @($MusicRoot) | Out-Null
    }
    else {
        Initialize-PackagedEnvironment
    }
    Sync-PackagedMusicRootOverride

    $port = Get-PackagedAppPort
    if ($Lan) {
        $address = Resolve-PackagedLanIpv4Address -RequestedAddress $LanAddress
        $adminToken = Invoke-PackagedConfiguration -Arguments @(
            'network',
            '--address', $address,
            '--port', $port,
            '--lan', 'true',
            '--hostname', [System.Net.Dns]::GetHostName()
        )
    }
    else {
        $address = '127.0.0.1'
        Invoke-PackagedConfiguration -Arguments @(
            'network',
            '--address', $address,
            '--port', $port,
            '--lan', 'false'
        ) | Out-Null
    }

    $buildArguments = if ($NoBuild) { @() } else { @('--build') }
    Assert-PackagedAudioIntelligenceConfiguration
    $analysisWorker = Get-PackagedAnalysisWorkerService
    $inactiveAnalysisWorker = if ($analysisWorker -eq 'queue-analysis-ai') {
        'queue-analysis'
    }
    else {
        'queue-analysis-ai'
    }

    Write-Host 'Starting packaged PostgreSQL...'
    Invoke-PackagedCompose -Arguments (@('up', '-d') + $buildArguments + @('postgres'))

    Write-Host 'Running database migrations...'
    Invoke-PackagedCompose -Arguments (@('up') + $buildArguments + @('--force-recreate', '--abort-on-container-exit', '--exit-code-from', 'migrate', 'migrate'))

    Write-Host 'Starting packaged app services...'
    Invoke-PackagedCompose -Arguments @('stop', $inactiveAnalysisWorker) -AllowExampleEnvironment
    Invoke-PackagedCompose -Arguments (
        @('up', '-d') +
        $buildArguments +
        @('backend', 'queue-default', 'queue-scans', $analysisWorker, 'scheduler', 'web')
    )

    Write-Host ''
    $statusArguments = if ($analysisWorker -eq 'queue-analysis-ai') {
        @('--profile', 'audio-intelligence', 'ps')
    }
    else {
        @('ps')
    }
    Invoke-PackagedCompose -Arguments $statusArguments
    Write-Host ''
    Write-Host "Sonotheque is available at http://${address}:$port/"

    if ($Lan) {
        Write-Host ''
        Write-Host 'Enter the generated admin token in Settings > Security from LAN devices:'
        Write-Host $adminToken
        Write-Host ''
        Write-Host 'If another device cannot connect, allow only the selected port for Private networks and LocalSubnet:'
        Write-Host "New-NetFirewallRule -DisplayName 'Sonotheque Packaged LAN' -Direction Inbound -Action Allow -Protocol TCP -LocalPort $port -LocalAddress $address -RemoteAddress LocalSubnet -Profile Private"
    }
}
catch {
    Write-Error $_
    exit 1
}

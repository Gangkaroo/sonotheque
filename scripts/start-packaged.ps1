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
    if (-not [string]::IsNullOrWhiteSpace($MusicRoot)) {
        if (Test-Path -LiteralPath $script:PackagedRootsPath) {
            throw '-MusicRoot cannot replace an existing generated root configuration. Run Configure Sonotheque Folders.cmd instead.'
        }
        Set-PackagedMusicRoots -MusicRoots @($MusicRoot) | Out-Null
    }
    else {
        Initialize-PackagedEnvironment
    }

    $port = Get-PackagedAppPort
    if ($Lan) {
        $address = Resolve-PackagedLanIpv4Address -RequestedAddress $LanAddress
        $adminToken = Get-PackagedEnvValue -Name 'SONOTHEQUE_ADMIN_TOKEN'
        if ([string]::IsNullOrWhiteSpace($adminToken) -or $adminToken.Length -lt 32) {
            $adminToken = New-PackagedHexSecret
            Set-PackagedEnvValue -Name 'SONOTHEQUE_ADMIN_TOKEN' -Value $adminToken
            Write-Host 'Generated LAN admin token for packaged mode.'
        }

        Set-PackagedEnvValue -Name 'APP_HTTP_BIND' -Value $address
        Set-PackagedEnvValue -Name 'APP_URL' -Value "http://${address}:$port"
        Set-PackagedEnvValue -Name 'SONOTHEQUE_LAN_ENABLED' -Value 'true'
        Set-PackagedEnvValue -Name 'SONOTHEQUE_LOCAL_PROXY_ENABLED' -Value 'false'
        Set-PackagedEnvValue -Name 'SONOTHEQUE_TRUSTED_HOSTS' -Value ("localhost,127.0.0.1,::1,$address,$([System.Net.Dns]::GetHostName())")
    }
    else {
        $address = '127.0.0.1'
        Set-PackagedEnvValue -Name 'APP_HTTP_BIND' -Value $address
        Set-PackagedEnvValue -Name 'APP_URL' -Value "http://${address}:$port"
        Set-PackagedEnvValue -Name 'SONOTHEQUE_LAN_ENABLED' -Value 'false'
        Set-PackagedEnvValue -Name 'SONOTHEQUE_LOCAL_PROXY_ENABLED' -Value 'true'
        Set-PackagedEnvValue -Name 'SONOTHEQUE_TRUSTED_HOSTS' -Value 'localhost,127.0.0.1,::1'
    }

    $buildArguments = if ($NoBuild) { @() } else { @('--build') }

    Write-Host 'Starting packaged PostgreSQL...'
    Invoke-PackagedCompose -Arguments (@('up', '-d') + $buildArguments + @('postgres'))

    Write-Host 'Running database migrations...'
    Invoke-PackagedCompose -Arguments (@('up') + $buildArguments + @('--force-recreate', '--abort-on-container-exit', '--exit-code-from', 'migrate', 'migrate'))

    Write-Host 'Starting packaged app services...'
    Invoke-PackagedCompose -Arguments (
        @('up', '-d') +
        $buildArguments +
        @('backend', 'queue-default', 'queue-scans', 'queue-analysis', 'scheduler', 'web')
    )

    Write-Host ''
    Invoke-PackagedCompose -Arguments @('ps')
    Write-Host ''
    Write-Host "Sonotheque is available at http://${address}:$port/"

    if ($Lan) {
        Write-Host ''
        Write-Host 'Enter the generated admin token in Settings > Security from LAN devices:'
        Write-Host (Get-PackagedEnvValue -Name 'SONOTHEQUE_ADMIN_TOKEN')
        Write-Host ''
        Write-Host 'If another device cannot connect, allow only the selected port for Private networks and LocalSubnet:'
        Write-Host "New-NetFirewallRule -DisplayName 'Sonotheque Packaged LAN' -Direction Inbound -Action Allow -Protocol TCP -LocalPort $port -LocalAddress $address -RemoteAddress LocalSubnet -Profile Private"
    }
}
catch {
    Write-Error $_
    exit 1
}

Set-StrictMode -Version Latest

$script:RepositoryRoot = Split-Path -Parent $PSScriptRoot
$script:PackagedComposeFile = Join-Path $script:RepositoryRoot 'compose.packaged.yaml'
$script:PackagedComposeOverrideFile = Join-Path $script:RepositoryRoot 'compose.packaged.override.yaml'
$script:PackagedEnvironmentPath = Join-Path $script:RepositoryRoot '.env.packaged'
$script:PackagedEnvironmentExamplePath = Join-Path $script:RepositoryRoot '.env.packaged.example'
$script:PackagedRootsPath = Join-Path $script:RepositoryRoot 'packaged-roots.json'

function Assert-PackagedDockerAvailable {
    if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Docker was not found on PATH. Start Docker Desktop and try again.'
    }

    & docker version *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker is not reachable. Start Docker Desktop and try again.'
    }
}

function New-PackagedBase64Key {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    }
    finally {
        $generator.Dispose()
    }

    return 'base64:' + [Convert]::ToBase64String($bytes)
}

function New-PackagedHexSecret {
    $bytes = New-Object byte[] 24
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    }
    finally {
        $generator.Dispose()
    }

    return [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
}

function Get-PackagedEnvironmentFile {
    param([switch]$AllowExample)

    if (Test-Path -LiteralPath $script:PackagedEnvironmentPath) {
        return $script:PackagedEnvironmentPath
    }

    if ($AllowExample -and (Test-Path -LiteralPath $script:PackagedEnvironmentExamplePath)) {
        return $script:PackagedEnvironmentExamplePath
    }

    throw '.env.packaged does not exist. Run scripts/start-packaged.ps1 first.'
}

function Get-PackagedEnvValue {
    param(
        [Parameter(Mandatory)][string]$Name,
        [string]$Path = $script:PackagedEnvironmentPath
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    $pattern = '^\s*' + [regex]::Escape($Name) + '\s*=\s*(.*)$'
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -notmatch $pattern) {
            continue
        }

        $value = $Matches[1].Trim()
        if ($value.Length -ge 2 -and (
            ($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))
        )) {
            return $value.Substring(1, $value.Length - 2)
        }

        return ($value -replace '\s+#.*$', '').Trim()
    }

    return $null
}

function Set-PackagedEnvValue {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][AllowEmptyString()][string]$Value
    )

    if (-not (Test-Path -LiteralPath $script:PackagedEnvironmentPath)) {
        throw '.env.packaged does not exist.'
    }

    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($line in Get-Content -LiteralPath $script:PackagedEnvironmentPath) {
        $lines.Add($line)
    }

    $pattern = '^\s*' + [regex]::Escape($Name) + '\s*='
    $replacement = "${Name}=${Value}"
    $updated = $false
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match $pattern) {
            $lines[$index] = $replacement
            $updated = $true
            break
        }
    }

    if (-not $updated) {
        $lines.Add($replacement)
    }

    Set-Content -LiteralPath $script:PackagedEnvironmentPath -Value $lines -Encoding utf8
}

function Initialize-PackagedEnvironment {
    param([string]$MusicRoot)

    if (-not (Test-Path -LiteralPath $script:PackagedEnvironmentPath)) {
        Copy-Item -LiteralPath $script:PackagedEnvironmentExamplePath -Destination $script:PackagedEnvironmentPath
        Write-Host 'Created .env.packaged from .env.packaged.example.'
    }

    $appKey = Get-PackagedEnvValue -Name 'APP_KEY'
    if ([string]::IsNullOrWhiteSpace($appKey)) {
        Set-PackagedEnvValue -Name 'APP_KEY' -Value (New-PackagedBase64Key)
        Write-Host 'Generated APP_KEY for packaged mode.'
    }

    $password = Get-PackagedEnvValue -Name 'POSTGRES_PASSWORD'
    if ([string]::IsNullOrWhiteSpace($password) -or $password -eq 'change-this-local-password') {
        Set-PackagedEnvValue -Name 'POSTGRES_PASSWORD' -Value ('ml_' + (New-PackagedHexSecret))
        Write-Host 'Generated PostgreSQL password for packaged mode.'
    }

    if (-not [string]::IsNullOrWhiteSpace($MusicRoot)) {
        if (-not (Test-Path -LiteralPath $MusicRoot)) {
            throw "Music root does not exist: $MusicRoot"
        }

        Set-PackagedEnvValue -Name 'SONOTHEQUE_ROOT_1' -Value (Resolve-Path -LiteralPath $MusicRoot).Path
    }
    else {
        $configuredRoot = Get-PackagedEnvValue -Name 'SONOTHEQUE_ROOT_1'
        if ([string]::IsNullOrWhiteSpace($configuredRoot) -or $configuredRoot -eq './packaged/music-root-1') {
            $placeholderRoot = Join-Path $script:RepositoryRoot 'packaged\music-root-1'
            New-Item -ItemType Directory -Path $placeholderRoot -Force | Out-Null
            Set-PackagedEnvValue -Name 'SONOTHEQUE_ROOT_1' -Value $placeholderRoot
            Write-Host "Using placeholder music mount: $placeholderRoot"
            Write-Host 'Pass -MusicRoot "G:\Music" to mount a real music folder.'
        }
    }
}

function Test-PackagedPrivateIpv4Address {
    param([Parameter(Mandatory)][string]$Address)

    $parsedAddress = $null
    if (-not [System.Net.IPAddress]::TryParse($Address, [ref]$parsedAddress) -or
        $parsedAddress.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) {
        return $false
    }

    $bytes = $parsedAddress.GetAddressBytes()
    return $bytes[0] -eq 10 -or
        ($bytes[0] -eq 172 -and $bytes[1] -ge 16 -and $bytes[1] -le 31) -or
        ($bytes[0] -eq 192 -and $bytes[1] -eq 168)
}

function Get-PackagedLocalLanIpv4Addresses {
    $addresses = foreach ($networkInterface in [System.Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces()) {
        if ($networkInterface.OperationalStatus -ne [System.Net.NetworkInformation.OperationalStatus]::Up -or
            $networkInterface.NetworkInterfaceType -in @(
                [System.Net.NetworkInformation.NetworkInterfaceType]::Loopback,
                [System.Net.NetworkInformation.NetworkInterfaceType]::Tunnel
            )) {
            continue
        }

        $properties = $networkInterface.GetIPProperties()
        $hasIpv4Gateway = @($properties.GatewayAddresses | Where-Object {
            $_.Address.AddressFamily -eq [System.Net.Sockets.AddressFamily]::InterNetwork -and
            -not $_.Address.Equals([System.Net.IPAddress]::Any)
        }).Count -gt 0
        if (-not $hasIpv4Gateway) {
            continue
        }

        foreach ($unicastAddress in $properties.UnicastAddresses) {
            $address = $unicastAddress.Address.ToString()
            if (Test-PackagedPrivateIpv4Address -Address $address) {
                $address
            }
        }
    }

    return @($addresses | Select-Object -Unique)
}

function Resolve-PackagedLanIpv4Address {
    param([string]$RequestedAddress)

    if (-not [string]::IsNullOrWhiteSpace($RequestedAddress)) {
        if (-not (Test-PackagedPrivateIpv4Address -Address $RequestedAddress)) {
            throw "LAN address '$RequestedAddress' is not a private IPv4 address."
        }

        if ($RequestedAddress -notin (Get-PackagedLocalLanIpv4Addresses)) {
            throw "LAN address '$RequestedAddress' is not assigned to this computer."
        }

        return $RequestedAddress
    }

    $addresses = Get-PackagedLocalLanIpv4Addresses
    if (@($addresses).Count -eq 0) {
        throw 'No private IPv4 address with a default gateway was found. Pass -LanAddress explicitly.'
    }

    if (@($addresses).Count -gt 1) {
        throw "Several private IPv4 addresses were found ($($addresses -join ', ')). Pass -LanAddress explicitly."
    }

    return [string]$addresses
}

function Get-PackagedAppPort {
    $port = Get-PackagedEnvValue -Name 'APP_HTTP_PORT'
    if ([string]::IsNullOrWhiteSpace($port)) {
        return '8080'
    }

    return $port
}

function Get-PackagedMusicRoots {
    if (Test-Path -LiteralPath $script:PackagedRootsPath) {
        $configuration = Get-Content -LiteralPath $script:PackagedRootsPath -Raw | ConvertFrom-Json
        return @($configuration.roots | ForEach-Object { [string] $_.hostPath })
    }

    $configuredRoot = Get-PackagedEnvValue -Name 'SONOTHEQUE_ROOT_1'
    if ([string]::IsNullOrWhiteSpace($configuredRoot) -or $configuredRoot -eq './packaged/music-root-1') {
        return @()
    }

    return @($configuredRoot)
}

function Set-PackagedMusicRoots {
    param([Parameter(Mandatory)][string[]]$MusicRoots)

    $resolvedRoots = [System.Collections.Generic.List[string]]::new()
    foreach ($musicRoot in $MusicRoots) {
        if ([string]::IsNullOrWhiteSpace($musicRoot)) {
            continue
        }
        if (-not (Test-Path -LiteralPath $musicRoot -PathType Container)) {
            throw "Music root does not exist or is not a folder: $musicRoot"
        }

        $resolvedRoot = (Resolve-Path -LiteralPath $musicRoot).Path
        if ($resolvedRoot -in $resolvedRoots) {
            continue
        }

        $resolvedRootPrefix = $resolvedRoot.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
        foreach ($existingRoot in $resolvedRoots) {
            $existingRootPrefix = $existingRoot.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
            if ($resolvedRoot.StartsWith($existingRootPrefix, [System.StringComparison]::OrdinalIgnoreCase) -or
                $existingRoot.StartsWith($resolvedRootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
                throw "Music roots must not overlap: '$resolvedRoot' and '$existingRoot'."
            }
        }
        $resolvedRoots.Add($resolvedRoot)
    }

    if ($resolvedRoots.Count -eq 0) {
        throw 'At least one music folder must be configured.'
    }

    Initialize-PackagedEnvironment -MusicRoot $resolvedRoots[0]

    $rootEntries = for ($index = 0; $index -lt $resolvedRoots.Count; $index++) {
        [ordered]@{
            hostPath = $resolvedRoots[$index]
            containerPath = "/music/root-$($index + 1)"
        }
    }
    $configuration = [ordered]@{
        version = 1
        roots = @($rootEntries)
    }
    $configuration | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $script:PackagedRootsPath -Encoding utf8

    $lines = [System.Collections.Generic.List[string]]::new()
    $lines.Add('# Generated by scripts/configure-packaged-roots.ps1. Do not edit while Sonotheque is running.')
    $lines.Add('services:')
    foreach ($service in @(
        'migrate',
        'backend',
        'queue-default',
        'queue-scans',
        'queue-analysis',
        'scheduler'
    )) {
        $lines.Add("  ${service}:")
        $lines.Add('    volumes:')
        for ($index = 0; $index -lt $resolvedRoots.Count; $index++) {
            $escapedSource = $resolvedRoots[$index].Replace("'", "''")
            $lines.Add('      - type: bind')
            $lines.Add("        source: '${escapedSource}'")
            $lines.Add("        target: /music/root-$($index + 1)")
        }
    }
    Set-Content -LiteralPath $script:PackagedComposeOverrideFile -Value $lines -Encoding utf8

    return @($rootEntries)
}

function Invoke-PackagedCompose {
    param(
        [Parameter(Mandatory)][string[]]$Arguments,
        [switch]$AllowExampleEnvironment
    )

    $environmentFile = Get-PackagedEnvironmentFile -AllowExample:$AllowExampleEnvironment
    $composeArguments = @('--env-file', $environmentFile, '-f', $script:PackagedComposeFile)
    if (Test-Path -LiteralPath $script:PackagedComposeOverrideFile) {
        $composeArguments += @('-f', $script:PackagedComposeOverrideFile)
    }

    Push-Location $script:RepositoryRoot
    try {
        & docker compose @composeArguments @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "docker compose exited with code $LASTEXITCODE."
        }
    }
    finally {
        Pop-Location
    }
}

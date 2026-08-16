Set-StrictMode -Version Latest

$script:RepositoryRoot = Split-Path -Parent $PSScriptRoot
$script:PackagedComposeFile = Join-Path $script:RepositoryRoot 'compose.packaged.yaml'
$script:PackagedComposeOverrideFile = Join-Path $script:RepositoryRoot 'compose.packaged.override.yaml'
$script:PackagedEnvironmentPath = Join-Path $script:RepositoryRoot '.env.packaged'
$script:PackagedEnvironmentExamplePath = Join-Path $script:RepositoryRoot '.env.packaged.example'
$script:PackagedRootsPath = Join-Path $script:RepositoryRoot 'packaged-roots.json'
$script:PackagedConfigurationImage = 'php:8.5-cli-alpine'

function Assert-PackagedDockerAvailable {
    if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Docker was not found on PATH. Start Docker Desktop and try again.'
    }

    & docker version *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'Docker is not reachable. Start Docker Desktop and try again.'
    }
}

function Invoke-PackagedConfiguration {
    param([Parameter(Mandatory)][string[]]$Arguments)

    $dockerArguments = @(
        'run',
        '--rm',
        '--volume', "${script:RepositoryRoot}:/package",
        '--workdir', '/package',
        $script:PackagedConfigurationImage,
        'php',
        'scripts/packaged-config.php'
    ) + $Arguments

    & docker @dockerArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Packaged configuration exited with code $LASTEXITCODE."
    }
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

    $arguments = @('init')
    if (-not [string]::IsNullOrWhiteSpace($MusicRoot)) {
        if (-not (Test-Path -LiteralPath $MusicRoot -PathType Container)) {
            throw "Music root does not exist: $MusicRoot"
        }

        $arguments += @('--music-root', ((Resolve-Path -LiteralPath $MusicRoot).Path))
    }

    Invoke-PackagedConfiguration -Arguments $arguments

    if ([string]::IsNullOrWhiteSpace($MusicRoot)) {
        $configuredRoot = Get-PackagedEnvValue -Name 'SONOTHEQUE_ROOT_1'
        if ([string]::IsNullOrWhiteSpace($configuredRoot) -or $configuredRoot -eq './packaged/music-root-1') {
            $placeholderRoot = Join-Path $script:RepositoryRoot 'packaged\music-root-1'
            New-Item -ItemType Directory -Path $placeholderRoot -Force | Out-Null
            Invoke-PackagedConfiguration -Arguments @(
                'set', '--name', 'SONOTHEQUE_ROOT_1', '--value', $placeholderRoot
            )
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

function Test-PackagedAudioIntelligenceEnabled {
    return (Get-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_DRIVER') -eq 'essentia_docker'
}

function Get-PackagedAnalysisWorkerService {
    if (Test-PackagedAudioIntelligenceEnabled) {
        return 'queue-analysis-ai'
    }

    return 'queue-analysis'
}

function Assert-PackagedAudioIntelligenceConfiguration {
    if (-not (Test-PackagedAudioIntelligenceEnabled)) {
        return
    }

    $modelDirectory = Get-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_MODEL_DIRECTORY'
    $modelFilename = Get-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_MODEL_FILENAME'
    if ([string]::IsNullOrWhiteSpace($modelDirectory) -or
        [string]::IsNullOrWhiteSpace($modelFilename)) {
        throw 'Packaged Audio Intelligence is enabled, but its model path is incomplete. Run Configure Sonotheque Audio Intelligence.cmd.'
    }

    $modelPath = Join-Path $modelDirectory $modelFilename
    if (-not [System.IO.Path]::IsPathRooted($modelPath)) {
        $modelPath = Join-Path $script:RepositoryRoot $modelPath
    }
    if (-not (Test-Path -LiteralPath $modelPath -PathType Leaf)) {
        throw "The configured Audio Intelligence model does not exist: $modelPath"
    }

    $accelerator = Get-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_ACCELERATOR'
    $imageName = if ($accelerator -eq 'cuda') {
        Get-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_BENCHMARK_CUDA_IMAGE'
    }
    else {
        Get-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_BENCHMARK_CPU_IMAGE'
    }
    & docker image inspect $imageName *> $null
    if ($LASTEXITCODE -ne 0) {
        throw "The configured Audio Intelligence image is missing: $imageName. Run Configure Sonotheque Audio Intelligence.cmd."
    }
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

    $arguments = @('roots', '--case-insensitive', 'true')
    foreach ($resolvedRoot in $resolvedRoots) {
        $arguments += @('--root', $resolvedRoot)
    }
    Invoke-PackagedConfiguration -Arguments $arguments

    $rootEntries = for ($index = 0; $index -lt $resolvedRoots.Count; $index++) {
        [ordered]@{
            hostPath = $resolvedRoots[$index]
            containerPath = "/music/root-$($index + 1)"
        }
    }

    return @($rootEntries)
}

function Sync-PackagedMusicRootOverride {
    if (-not (Test-Path -LiteralPath $script:PackagedRootsPath)) {
        return
    }

    Set-PackagedMusicRoots -MusicRoots @(Get-PackagedMusicRoots) | Out-Null
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

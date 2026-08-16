Set-StrictMode -Version Latest

$script:RepositoryRoot = Split-Path -Parent $PSScriptRoot
$script:RuntimeLogDirectory = Join-Path $script:RepositoryRoot 'runtime-logs'
$script:BackendDirectory = Join-Path $script:RepositoryRoot 'backend'
$script:FrontendDirectory = Join-Path $script:RepositoryRoot 'frontend'
$script:RuntimeModeStatePath = Join-Path $script:RuntimeLogDirectory 'runtime-mode.json'

function Initialize-RuntimeDirectory {
    New-Item -ItemType Directory -Path $script:RuntimeLogDirectory -Force | Out-Null
}

function Get-ProcessStatePath {
    param([Parameter(Mandatory)][string]$Name)

    return Join-Path $script:RuntimeLogDirectory "$Name.process.json"
}

function Get-RuntimeModeState {
    if (-not (Test-Path -LiteralPath $script:RuntimeModeStatePath)) {
        return $null
    }

    try {
        return Get-Content -LiteralPath $script:RuntimeModeStatePath -Raw | ConvertFrom-Json
    }
    catch {
        return $null
    }
}

function Set-RuntimeModeState {
    param(
        [Parameter(Mandatory)][ValidateSet('local', 'lan')][string]$Mode,
        [Parameter(Mandatory)][string]$FrontendHost
    )

    @{
        mode = $Mode
        frontendHost = $FrontendHost
        frontendUrl = "http://${FrontendHost}:5173/"
        apiUrl = 'http://127.0.0.1:8000/'
        startedAt = (Get-Date).ToUniversalTime().ToString('o')
    } | ConvertTo-Json | Set-Content -LiteralPath $script:RuntimeModeStatePath -Encoding utf8
}

function Remove-RuntimeModeState {
    Remove-Item -LiteralPath $script:RuntimeModeStatePath -Force -ErrorAction SilentlyContinue
}

function Get-RuntimeSetting {
    param([Parameter(Mandatory)][string]$Name)

    $processValue = [Environment]::GetEnvironmentVariable($Name, 'Process')
    if (-not [string]::IsNullOrWhiteSpace($processValue)) {
        return $processValue
    }

    $environmentPath = Join-Path $script:BackendDirectory '.env'
    if (-not (Test-Path -LiteralPath $environmentPath)) {
        return $null
    }

    $pattern = '^\s*' + [regex]::Escape($Name) + '\s*=\s*(.*)$'
    foreach ($line in Get-Content -LiteralPath $environmentPath) {
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

function Test-PrivateIpv4Address {
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

function Get-LocalLanIpv4Addresses {
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
            if (Test-PrivateIpv4Address -Address $address) {
                $address
            }
        }
    }

    return @($addresses | Select-Object -Unique)
}

function Resolve-LanIpv4Address {
    param([string]$RequestedAddress)

    if (-not [string]::IsNullOrWhiteSpace($RequestedAddress)) {
        if (-not (Test-PrivateIpv4Address -Address $RequestedAddress)) {
            throw "LAN address '$RequestedAddress' is not a private IPv4 address."
        }

        if ($RequestedAddress -notin (Get-LocalLanIpv4Addresses)) {
            throw "LAN address '$RequestedAddress' is not assigned to this computer."
        }

        return $RequestedAddress
    }

    $addresses = Get-LocalLanIpv4Addresses

    if (@($addresses).Count -eq 0) {
        throw 'No private IPv4 address with a default gateway was found. Pass -LanAddress explicitly.'
    }

    if (@($addresses).Count -gt 1) {
        throw "Several private IPv4 addresses were found ($($addresses -join ', ')). Pass -LanAddress explicitly."
    }

    return [string]$addresses
}

function Get-LanTrustedHosts {
    param([Parameter(Mandatory)][string]$LanAddress)

    $configuredValue = Get-RuntimeSetting -Name 'SONOTHEQUE_TRUSTED_HOSTS'
    $configuredHosts = if ([string]::IsNullOrWhiteSpace($configuredValue)) {
        @()
    }
    else {
        $configuredValue -split ','
    }

    return @(
        'localhost'
        '127.0.0.1'
        '::1'
        $LanAddress
        [System.Net.Dns]::GetHostName()
        $configuredHosts
    ) | Where-Object { $null -ne $_ } | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' } | Select-Object -Unique
}

function Get-LanAdminToken {
    $token = Get-RuntimeSetting -Name 'SONOTHEQUE_ADMIN_TOKEN'
    if ([string]::IsNullOrWhiteSpace($token) -or $token.Length -lt 32) {
        throw 'LAN mode requires SONOTHEQUE_ADMIN_TOKEN in backend/.env with at least 32 characters.'
    }

    return $token
}

function Get-ManagedProcess {
    param([Parameter(Mandatory)][string]$Name)

    $statePath = Get-ProcessStatePath -Name $Name
    if (-not (Test-Path -LiteralPath $statePath)) {
        return $null
    }

    try {
        $state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
        $process = Get-Process -Id ([int]$state.processId) -ErrorAction Stop
        if ($process.StartTime.ToUniversalTime().Ticks -ne [long]$state.startTimeUtcTicks) {
            return $null
        }

        return $process
    }
    catch {
        return $null
    }
}

function Remove-StaleProcessState {
    param([Parameter(Mandatory)][string]$Name)

    if ($null -eq (Get-ManagedProcess -Name $Name)) {
        Remove-Item -LiteralPath (Get-ProcessStatePath -Name $Name) -Force -ErrorAction SilentlyContinue
    }
}

function Move-ExistingRuntimeLog {
    param([Parameter(Mandatory)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return
    }

    $log = Get-Item -LiteralPath $Path -ErrorAction SilentlyContinue
    if ($null -eq $log -or $log.Length -eq 0) {
        return
    }

    $directory = Split-Path -Parent $Path
    $baseName = [System.IO.Path]::GetFileNameWithoutExtension($Path)
    $extension = [System.IO.Path]::GetExtension($Path)
    $timestamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssfffZ')
    $destination = Join-Path $directory "$baseName.$timestamp$extension"

    Move-Item -LiteralPath $Path -Destination $destination -Force
}

function Start-ManagedProcess {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$ArgumentList,
        [Parameter(Mandatory)][string]$WorkingDirectory,
        [Parameter(Mandatory)][string]$StandardOutputPath,
        [Parameter(Mandatory)][string]$StandardErrorPath,
        [hashtable]$EnvironmentVariables = @{}
    )

    $existing = Get-ManagedProcess -Name $Name
    if ($null -ne $existing) {
        return $existing
    }

    Remove-StaleProcessState -Name $Name
    Move-ExistingRuntimeLog -Path $StandardOutputPath
    Move-ExistingRuntimeLog -Path $StandardErrorPath
    $previousEnvironment = @{}
    try {
        foreach ($environmentName in $EnvironmentVariables.Keys) {
            $previousEnvironment[$environmentName] = [Environment]::GetEnvironmentVariable($environmentName, 'Process')
            [Environment]::SetEnvironmentVariable(
                $environmentName,
                [string]$EnvironmentVariables[$environmentName],
                'Process'
            )
        }

        $process = Start-Process `
            -FilePath $FilePath `
            -ArgumentList $ArgumentList `
            -WorkingDirectory $WorkingDirectory `
            -WindowStyle Hidden `
            -RedirectStandardOutput $StandardOutputPath `
            -RedirectStandardError $StandardErrorPath `
            -PassThru
    }
    finally {
        foreach ($environmentName in $EnvironmentVariables.Keys) {
            [Environment]::SetEnvironmentVariable(
                $environmentName,
                $previousEnvironment[$environmentName],
                'Process'
            )
        }
    }

    Start-Sleep -Milliseconds 750
    if ($process.HasExited) {
        $details = if (Test-Path -LiteralPath $StandardErrorPath) {
            (Get-Content -LiteralPath $StandardErrorPath -Tail 10) -join [Environment]::NewLine
        }
        else {
            'No error log was produced.'
        }

        throw "$Name stopped during startup.$([Environment]::NewLine)$details"
    }

    @{
        processId = $process.Id
        startTimeUtcTicks = $process.StartTime.ToUniversalTime().Ticks
        executable = $FilePath
        workingDirectory = $WorkingDirectory
        arguments = $ArgumentList
    } | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Get-ProcessStatePath -Name $Name) -Encoding utf8

    return $process
}

function Stop-ProcessTree {
    param([Parameter(Mandatory)][int]$ProcessId)

    $children = Get-CimInstance Win32_Process -Filter "ParentProcessId = $ProcessId" -ErrorAction SilentlyContinue
    foreach ($child in $children) {
        Stop-ProcessTree -ProcessId ([int]$child.ProcessId)
    }

    Stop-Process -Id $ProcessId -Force -ErrorAction SilentlyContinue
}

function Stop-ManagedProcess {
    param([Parameter(Mandatory)][string]$Name)

    $process = Get-ManagedProcess -Name $Name
    if ($null -eq $process) {
        Remove-StaleProcessState -Name $Name
        return $false
    }

    Stop-ProcessTree -ProcessId $process.Id
    Remove-Item -LiteralPath (Get-ProcessStatePath -Name $Name) -Force -ErrorAction SilentlyContinue
    return $true
}

function Resolve-Php85 {
    $candidates = [System.Collections.Generic.List[string]]::new()
    if (-not [string]::IsNullOrWhiteSpace($env:SONOTHEQUE_PHP)) {
        $candidates.Add($env:SONOTHEQUE_PHP)
    }

    $pathPhp = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $pathPhp) {
        $candidates.Add($pathPhp.Source)
    }

    $wingetRoot = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages'
    if (Test-Path -LiteralPath $wingetRoot) {
        Get-ChildItem -LiteralPath $wingetRoot -Directory -Filter 'PHP.PHP.8.5_*' -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            ForEach-Object {
                $candidate = Join-Path $_.FullName 'php.exe'
                if (Test-Path -LiteralPath $candidate) {
                    $candidates.Add($candidate)
                }
            }
    }

    foreach ($candidate in ($candidates | Select-Object -Unique)) {
        if (-not (Test-Path -LiteralPath $candidate)) {
            continue
        }

        $version = (& $candidate --version 2>$null | Select-Object -First 1 | Out-String).Trim()
        if ($version -match '^PHP 8\.5\.') {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    throw 'PHP 8.5 was not found. Set SONOTHEQUE_PHP to the PHP 8.5 executable.'
}

function Resolve-Node {
    $node = Get-Command node -ErrorAction SilentlyContinue
    if ($null -eq $node) {
        throw 'Node.js was not found on PATH.'
    }

    return $node.Source
}

function Assert-RuntimePrerequisites {
    if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Docker was not found on PATH. Start Docker Desktop and try again.'
    }

    $requiredPaths = @(
        (Join-Path $script:BackendDirectory '.env'),
        (Join-Path $script:BackendDirectory 'vendor\autoload.php'),
        (Join-Path $script:FrontendDirectory 'node_modules\vite\bin\vite.js')
    )

    foreach ($path in $requiredPaths) {
        if (-not (Test-Path -LiteralPath $path)) {
            throw "Required runtime file is missing: $path"
        }
    }
}

function Get-PortOwner {
    param([Parameter(Mandatory)][int]$Port)

    $connection = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -eq $connection) {
        return $null
    }

    return [int]$connection.OwningProcess
}

function Test-HttpEndpoint {
    param(
        [Parameter(Mandatory)][string]$Uri,
        [hashtable]$Headers = @{}
    )

    try {
        $response = Invoke-WebRequest -Uri $Uri -Headers $Headers -UseBasicParsing -TimeoutSec 3
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 500
    }
    catch {
        return $false
    }
}

function Get-HttpStatusCode {
    param(
        [Parameter(Mandatory)][string]$Uri,
        [hashtable]$Headers = @{}
    )

    Add-Type -AssemblyName System.Net.Http
    $client = [System.Net.Http.HttpClient]::new()
    $request = [System.Net.Http.HttpRequestMessage]::new(
        [System.Net.Http.HttpMethod]::Get,
        $Uri
    )

    try {
        $client.Timeout = [TimeSpan]::FromSeconds(5)
        foreach ($header in $Headers.GetEnumerator()) {
            [void]$request.Headers.TryAddWithoutValidation([string]$header.Key, [string]$header.Value)
        }

        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        try {
            return [int]$response.StatusCode
        }
        finally {
            $response.Dispose()
        }
    }
    catch {
        return $null
    }
    finally {
        $request.Dispose()
        $client.Dispose()
    }
}

function Wait-HttpEndpoint {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$Uri,
        [int]$TimeoutSeconds = 30
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        if (Test-HttpEndpoint -Uri $Uri) {
            return
        }

        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    throw "$Name did not become healthy within $TimeoutSeconds seconds."
}

function Invoke-DockerCompose {
    param([Parameter(Mandatory)][string[]]$Arguments)

    Push-Location $script:RepositoryRoot
    try {
        & docker compose @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "docker compose exited with code $LASTEXITCODE."
        }
    }
    finally {
        Pop-Location
    }
}

function Stop-AudioAnalyzerContainers {
    $containerIds = @(
        & docker ps --all --quiet --filter 'label=sonotheque.audio-analyzer=true' 2>$null
    )
    if ($LASTEXITCODE -ne 0) {
        throw "Docker could not list Sonotheque audio analyzer containers."
    }

    foreach ($containerId in $containerIds) {
        $normalizedId = ([string]$containerId).Trim()
        if ($normalizedId -notmatch '^[a-f0-9]{12,64}$') {
            throw "Docker returned an invalid audio analyzer container identifier."
        }

        & docker rm --force $normalizedId | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "Docker could not remove audio analyzer container $normalizedId."
        }
    }
}

function Get-PostgresStatus {
    try {
        $running = (& docker inspect --format '{{.State.Running}}' sonotheque-postgres 2>$null | Out-String).Trim()
        if ($LASTEXITCODE -ne 0 -or $running -ne 'true') {
            return 'Stopped'
        }

        $health = (& docker inspect --format '{{.State.Health.Status}}' sonotheque-postgres 2>$null | Out-String).Trim()
        if ($health -eq 'healthy') {
            return 'Healthy'
        }

        return "Running ($health)"
    }
    catch {
        return 'Unavailable'
    }
}

function Wait-Postgres {
    param([int]$TimeoutSeconds = 60)

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        if ((Get-PostgresStatus) -eq 'Healthy') {
            return
        }

        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)

    throw "PostgreSQL did not become healthy within $TimeoutSeconds seconds."
}

function Find-ExternalQueueWorker {
    param([Parameter(Mandatory)][string]$Queue)

    $queueName = [regex]::Escape($Queue)

    return Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            $_.CommandLine -match 'artisan\s+queue:(work|listen)' -and (
                ($Queue -eq 'default' -and $_.CommandLine -notmatch '--queue(?:=|\s+)') -or
                $_.CommandLine -match "--queue(?:=|\s+)$queueName(?:,|\s|$)"
            )
        } |
        Select-Object -First 1
}

function Find-ExternalScheduler {
    return Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match 'artisan\s+schedule:work' } |
        Select-Object -First 1
}

function Get-QueueWorkerDefinitions {
    return @(
        [pscustomobject]@{
            Name = 'queue-default'
            Queue = 'default'
            Label = 'Interactive queue'
        }
        [pscustomobject]@{
            Name = 'queue-scans'
            Queue = 'scans'
            Label = 'Library scan queue'
        }
        [pscustomobject]@{
            Name = 'queue-analysis'
            Queue = 'analysis'
            Label = 'Audio analysis queue'
        }
    )
}

function Get-BackendRuntimeEnvironment {
    param(
        [Parameter(Mandatory)][ValidateSet('local', 'lan')][string]$Mode,
        [Parameter(Mandatory)][string]$FrontendHost
    )

    $environment = @{
        SONOTHEQUE_LAN_ENABLED = if ($Mode -eq 'lan') { 'true' } else { 'false' }
        SONOTHEQUE_TRUSTED_HOSTS = 'localhost,127.0.0.1,::1'
    }

    if ($Mode -eq 'lan') {
        $environment.SONOTHEQUE_ADMIN_TOKEN = Get-LanAdminToken
        $environment.SONOTHEQUE_TRUSTED_HOSTS = (
            Get-LanTrustedHosts -LanAddress $FrontendHost
        ) -join ','
    }

    return $environment
}

function Start-QueueWorker {
    param(
        [Parameter(Mandatory)][object]$Definition,
        [Parameter(Mandatory)][string]$PhpPath,
        [Parameter(Mandatory)][hashtable]$EnvironmentVariables
    )

    return Start-ManagedProcess `
        -Name $Definition.Name `
        -FilePath $PhpPath `
        -ArgumentList @(
            'artisan',
            'queue:work',
            "--queue=$($Definition.Queue)",
            '--tries=1',
            '--timeout=0',
            '--memory=512',
            '--sleep=1'
        ) `
        -WorkingDirectory $script:BackendDirectory `
        -StandardOutputPath (Join-Path $script:RuntimeLogDirectory "$($Definition.Name).out.log") `
        -StandardErrorPath (Join-Path $script:RuntimeLogDirectory "$($Definition.Name).err.log") `
        -EnvironmentVariables $EnvironmentVariables
}

function Get-RuntimeStatus {
    $runtimeMode = Get-RuntimeModeState
    $frontendHost = if ($null -ne $runtimeMode -and $runtimeMode.mode -eq 'lan') {
        [string]$runtimeMode.frontendHost
    }
    else {
        '127.0.0.1'
    }
    $frontendUri = "http://${frontendHost}:5173/"
    $postgresStatus = Get-PostgresStatus
    $apiProcess = Get-ManagedProcess -Name 'api'
    $apiOwner = Get-PortOwner -Port 8000
    $apiHealthy = Test-HttpEndpoint -Uri 'http://127.0.0.1:8000/up'
    $queueStatuses = foreach ($queue in (Get-QueueWorkerDefinitions)) {
        $queueProcess = Get-ManagedProcess -Name $queue.Name
        $externalQueue = if ($null -eq $queueProcess) {
            Find-ExternalQueueWorker -Queue $queue.Queue
        }
        else {
            $null
        }

        [pscustomobject]@{
            Service = $queue.Label
            Status = if ($null -ne $queueProcess -or $null -ne $externalQueue) { 'Running' } else { 'Stopped' }
            Details = if ($null -ne $queueProcess) { "managed PID $($queueProcess.Id)" } elseif ($null -ne $externalQueue) { "external PID $($externalQueue.ProcessId)" } else { '-' }
        }
    }
    $schedulerProcess = Get-ManagedProcess -Name 'scheduler'
    $externalScheduler = if ($null -eq $schedulerProcess) { Find-ExternalScheduler } else { $null }
    $frontendProcess = Get-ManagedProcess -Name 'frontend'
    $frontendOwner = Get-PortOwner -Port 5173
    $frontendHealthy = Test-HttpEndpoint -Uri $frontendUri
    $supervisorProcess = Get-ManagedProcess -Name 'worker-supervisor'

    return @(
        [pscustomobject]@{
            Service = 'PostgreSQL'
            Status = $postgresStatus
            Details = 'Docker container sonotheque-postgres'
        },
        [pscustomobject]@{
            Service = 'Laravel API'
            Status = if ($apiHealthy) { 'Healthy' } else { 'Stopped' }
            Details = if ($null -ne $apiProcess) { "managed PID $($apiProcess.Id)" } elseif ($null -ne $apiOwner) { "external PID $apiOwner" } else { '-' }
        },
        $queueStatuses
        [pscustomobject]@{
            Service = 'Worker supervisor'
            Status = if ($null -ne $supervisorProcess) {
                'Running'
            }
            elseif ($null -eq $runtimeMode) {
                'Not managed'
            }
            else {
                'Stopped'
            }
            Details = if ($null -ne $supervisorProcess) {
                "managed PID $($supervisorProcess.Id)"
            }
            elseif ($null -eq $runtimeMode) {
                'start.ps1 was not used'
            }
            else {
                '-'
            }
        },
        [pscustomobject]@{
            Service = 'Scheduler'
            Status = if ($null -ne $schedulerProcess -or $null -ne $externalScheduler) { 'Running' } else { 'Stopped' }
            Details = if ($null -ne $schedulerProcess) { "managed PID $($schedulerProcess.Id)" } elseif ($null -ne $externalScheduler) { "external PID $($externalScheduler.ProcessId)" } else { '-' }
        },
        [pscustomobject]@{
            Service = 'Vue frontend'
            Status = if ($frontendHealthy) { 'Healthy' } else { 'Stopped' }
            Details = if ($null -ne $frontendProcess) { "managed PID $($frontendProcess.Id), $frontendUri" } elseif ($null -ne $frontendOwner) { "external PID $frontendOwner" } else { $frontendUri }
        }
    )
}

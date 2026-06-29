Set-StrictMode -Version Latest

$script:RepositoryRoot = Split-Path -Parent $PSScriptRoot
$script:RuntimeLogDirectory = Join-Path $script:RepositoryRoot 'runtime-logs'
$script:BackendDirectory = Join-Path $script:RepositoryRoot 'backend'
$script:FrontendDirectory = Join-Path $script:RepositoryRoot 'frontend'

function Initialize-RuntimeDirectory {
    New-Item -ItemType Directory -Path $script:RuntimeLogDirectory -Force | Out-Null
}

function Get-ProcessStatePath {
    param([Parameter(Mandatory)][string]$Name)

    return Join-Path $script:RuntimeLogDirectory "$Name.process.json"
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

function Start-ManagedProcess {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$ArgumentList,
        [Parameter(Mandatory)][string]$WorkingDirectory,
        [Parameter(Mandatory)][string]$StandardOutputPath,
        [Parameter(Mandatory)][string]$StandardErrorPath
    )

    $existing = Get-ManagedProcess -Name $Name
    if ($null -ne $existing) {
        return $existing
    }

    Remove-StaleProcessState -Name $Name
    $process = Start-Process `
        -FilePath $FilePath `
        -ArgumentList $ArgumentList `
        -WorkingDirectory $WorkingDirectory `
        -WindowStyle Hidden `
        -RedirectStandardOutput $StandardOutputPath `
        -RedirectStandardError $StandardErrorPath `
        -PassThru

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
    if (-not [string]::IsNullOrWhiteSpace($env:MUSIC_LIBRARY_PHP)) {
        $candidates.Add($env:MUSIC_LIBRARY_PHP)
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

    throw 'PHP 8.5 was not found. Set MUSIC_LIBRARY_PHP to the PHP 8.5 executable.'
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
    param([Parameter(Mandatory)][string]$Uri)

    try {
        $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 3
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 500
    }
    catch {
        return $false
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

function Get-PostgresStatus {
    try {
        $running = (& docker inspect --format '{{.State.Running}}' music-library-postgres 2>$null | Out-String).Trim()
        if ($LASTEXITCODE -ne 0 -or $running -ne 'true') {
            return 'Stopped'
        }

        $health = (& docker inspect --format '{{.State.Health.Status}}' music-library-postgres 2>$null | Out-String).Trim()
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
    return Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match 'artisan\s+queue:(work|listen)' } |
        Select-Object -First 1
}

function Get-RuntimeStatus {
    $postgresStatus = Get-PostgresStatus
    $apiProcess = Get-ManagedProcess -Name 'api'
    $apiOwner = Get-PortOwner -Port 8000
    $apiHealthy = Test-HttpEndpoint -Uri 'http://127.0.0.1:8000/api/dashboard-metrics'
    $queueProcess = Get-ManagedProcess -Name 'queue-worker'
    $externalQueue = if ($null -eq $queueProcess) { Find-ExternalQueueWorker } else { $null }
    $frontendProcess = Get-ManagedProcess -Name 'frontend'
    $frontendOwner = Get-PortOwner -Port 5173
    $frontendHealthy = Test-HttpEndpoint -Uri 'http://127.0.0.1:5173/'

    return @(
        [pscustomobject]@{
            Service = 'PostgreSQL'
            Status = $postgresStatus
            Details = 'Docker container music-library-postgres'
        },
        [pscustomobject]@{
            Service = 'Laravel API'
            Status = if ($apiHealthy) { 'Healthy' } else { 'Stopped' }
            Details = if ($null -ne $apiProcess) { "managed PID $($apiProcess.Id)" } elseif ($null -ne $apiOwner) { "external PID $apiOwner" } else { '-' }
        },
        [pscustomobject]@{
            Service = 'Queue worker'
            Status = if ($null -ne $queueProcess -or $null -ne $externalQueue) { 'Running' } else { 'Stopped' }
            Details = if ($null -ne $queueProcess) { "managed PID $($queueProcess.Id)" } elseif ($null -ne $externalQueue) { "external PID $($externalQueue.ProcessId)" } else { '-' }
        },
        [pscustomobject]@{
            Service = 'Vue frontend'
            Status = if ($frontendHealthy) { 'Healthy' } else { 'Stopped' }
            Details = if ($null -ne $frontendProcess) { "managed PID $($frontendProcess.Id)" } elseif ($null -ne $frontendOwner) { "external PID $frontendOwner" } else { '-' }
        }
    )
}

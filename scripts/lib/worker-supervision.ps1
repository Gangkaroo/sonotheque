function New-WorkerSupervisionState {
    return @{ FailureCounts = @{}; NextAttempts = @{}; DatabaseStatus = $null }
}

function Write-WorkerSupervisorEvent {
    param(
        [Parameter(Mandatory)][ValidateSet('INFO', 'WARN', 'ERROR')][string]$Level,
        [Parameter(Mandatory)][string]$Message
    )

    try {
        $timestamp = (Get-Date).ToUniversalTime().ToString('o')
        "$timestamp [$Level] $Message" | Add-Content `
            -LiteralPath (Join-Path $script:RuntimeLogDirectory 'worker-supervisor-events.log') `
            -Encoding utf8 -ErrorAction Stop
    }
    catch {
        Write-Warning "Supervisor event could not be logged: $($_.Exception.Message)"
    }
}

function Invoke-BackgroundServiceSupervision {
    param(
        [Parameter(Mandatory)][hashtable]$State,
        [Parameter(Mandatory)][string]$PhpPath,
        [Parameter(Mandatory)][hashtable]$EnvironmentVariables,
        [Parameter(Mandatory)][bool]$DatabaseReady,
        [ValidateRange(2, 300)][int]$IntervalSeconds = 30
    )

    $definitions = @((Get-QueueWorkerDefinitions)) + @(
        [pscustomobject]@{ Name = 'scheduler'; Queue = $null; Label = 'Scheduler' }
    )
    foreach ($definition in $definitions) {
        $isScheduler = $definition.Name -eq 'scheduler'
        $queue = if ($isScheduler) { 'scheduler' } else { $definition.Queue }
        try {
            $managedProcess = Get-ManagedProcess -Name $definition.Name
            $externalProcess = if ($null -ne $managedProcess) { $null }
            elseif ($isScheduler) { Find-ExternalScheduler }
            else { Find-ExternalQueueWorker -Queue $definition.Queue }

            if ($null -ne $managedProcess -or $null -ne $externalProcess) {
                $State.FailureCounts.Remove($definition.Name)
                $State.NextAttempts.Remove($definition.Name)
                [pscustomobject]@{
                    queue = $queue
                    status = if ($null -ne $managedProcess) { 'managed' } else { 'external' }
                    processId = if ($null -ne $managedProcess) { $managedProcess.Id } else { $externalProcess.ProcessId }
                }
                continue
            }
            if (-not $DatabaseReady) {
                [pscustomobject]@{ queue = $queue; status = 'waiting-for-database'; processId = $null }
                continue
            }
            $nextAttempt = $State.NextAttempts[$definition.Name]
            if ($null -ne $nextAttempt -and (Get-Date) -lt $nextAttempt) {
                [pscustomobject]@{ queue = $queue; status = 'restart-wait'; processId = $null }
                continue
            }

            Write-WorkerSupervisorEvent -Level 'WARN' -Message "$($definition.Label) is not running; starting it."
            $process = if ($isScheduler) {
                Start-RuntimeScheduler -PhpPath $PhpPath -EnvironmentVariables $EnvironmentVariables
            }
            else {
                Start-QueueWorker -Definition $definition -PhpPath $PhpPath -EnvironmentVariables $EnvironmentVariables
            }
            $State.FailureCounts.Remove($definition.Name)
            $State.NextAttempts.Remove($definition.Name)
            Write-WorkerSupervisorEvent -Level 'INFO' -Message "$($definition.Label) restarted with PID $($process.Id)."
            [pscustomobject]@{ queue = $queue; status = 'restarted'; processId = $process.Id }
        }
        catch {
            $previousFailures = if ($State.FailureCounts.ContainsKey($definition.Name)) {
                [int]$State.FailureCounts[$definition.Name]
            }
            else { 0 }
            $failures = $previousFailures + 1
            $State.FailureCounts[$definition.Name] = $failures
            $retrySeconds = [Math]::Min(60, $IntervalSeconds * [Math]::Pow(2, [Math]::Min(6, $failures - 1)))
            $State.NextAttempts[$definition.Name] = (Get-Date).AddSeconds($retrySeconds)
            Write-WorkerSupervisorEvent -Level 'ERROR' -Message "$($definition.Label) restart failed; retrying in $retrySeconds seconds. $($_.Exception.Message)"
            [pscustomobject]@{ queue = $queue; status = 'restart-failed'; processId = $null }
        }
    }
}

function Invoke-WorkerSupervisionCycle {
    param(
        [Parameter(Mandatory)][hashtable]$State,
        [Parameter(Mandatory)][string]$PhpPath,
        [ValidateRange(2, 300)][int]$IntervalSeconds = 30
    )

    try {
        $runtimeMode = Get-RuntimeModeState
        if ($null -eq $runtimeMode) {
            if (-not (Test-Path -LiteralPath $script:RuntimeModeStatePath)) {
                Write-WorkerSupervisorEvent -Level 'INFO' -Message 'Runtime state was removed; worker supervision is stopping.'
                return [pscustomobject]@{ shouldContinue = $false }
            }
            throw 'Runtime state is temporarily unreadable; waiting for the next check.'
        }
        $environment = Get-BackendRuntimeEnvironment `
            -Mode ([string]$runtimeMode.mode) -FrontendHost ([string]$runtimeMode.frontendHost)
        $databaseStatus = Get-PostgresStatus
        if ($State.DatabaseStatus -ne $databaseStatus) {
            $level = if ($databaseStatus -eq 'Healthy') { 'INFO' } else { 'WARN' }
            Write-WorkerSupervisorEvent -Level $level -Message "PostgreSQL status: $databaseStatus. Missing background services restart only when it is healthy."
            $State.DatabaseStatus = $databaseStatus
        }
        $workers = @(Invoke-BackgroundServiceSupervision -State $State -PhpPath $PhpPath `
            -EnvironmentVariables $environment -DatabaseReady ($databaseStatus -eq 'Healthy') `
            -IntervalSeconds $IntervalSeconds)
        return [pscustomobject]@{
            shouldContinue = $true
            databaseStatus = $databaseStatus
            workers = $workers
            error = $null
        }
    }
    catch {
        Write-WorkerSupervisorEvent -Level 'ERROR' -Message "Supervisor check failed; retrying in $IntervalSeconds seconds. $($_.Exception.Message)"
        return [pscustomobject]@{
            shouldContinue = $true
            databaseStatus = 'Unavailable'
            workers = @()
            error = $_.Exception.Message
        }
    }
}

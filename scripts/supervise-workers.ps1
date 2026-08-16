[CmdletBinding()]
param(
    [ValidateRange(2, 300)][int]$IntervalSeconds = 30,
    [switch]$Once
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'runtime-common.ps1')

Initialize-RuntimeDirectory
$php = Resolve-Php85
$eventLogPath = Join-Path $script:RuntimeLogDirectory 'worker-supervisor-events.log'
$heartbeatPath = Join-Path $script:RuntimeLogDirectory 'worker-supervisor-heartbeat.json'
$failureCounts = @{}
$nextAttempts = @{}

function Write-SupervisorEvent {
    param(
        [Parameter(Mandatory)][ValidateSet('INFO', 'WARN', 'ERROR')][string]$Level,
        [Parameter(Mandatory)][string]$Message
    )

    $timestamp = (Get-Date).ToUniversalTime().ToString('o')
    "$timestamp [$Level] $Message" | Add-Content -LiteralPath $eventLogPath -Encoding utf8
}

Write-SupervisorEvent -Level 'INFO' -Message "Worker supervisor started with PID $PID."

try {
    while ($true) {
        $runtimeMode = Get-RuntimeModeState
        if ($null -eq $runtimeMode) {
            Write-SupervisorEvent -Level 'INFO' -Message 'Runtime state was removed; worker supervision is stopping.'
            break
        }

        $mode = [string]$runtimeMode.mode
        $frontendHost = [string]$runtimeMode.frontendHost
        $environment = Get-BackendRuntimeEnvironment -Mode $mode -FrontendHost $frontendHost
        $workerStates = [System.Collections.Generic.List[object]]::new()

        foreach ($definition in (Get-QueueWorkerDefinitions)) {
            $managedProcess = Get-ManagedProcess -Name $definition.Name
            $externalProcess = if ($null -eq $managedProcess) {
                Find-ExternalQueueWorker -Queue $definition.Queue
            }
            else {
                $null
            }

            if ($null -ne $managedProcess) {
                $failureCounts.Remove($definition.Name)
                $nextAttempts.Remove($definition.Name)
                $workerStates.Add([pscustomobject]@{
                    queue = $definition.Queue
                    status = 'managed'
                    processId = $managedProcess.Id
                })
                continue
            }

            if ($null -ne $externalProcess) {
                $workerStates.Add([pscustomobject]@{
                    queue = $definition.Queue
                    status = 'external'
                    processId = [int]$externalProcess.ProcessId
                })
                continue
            }

            $now = Get-Date
            $nextAttempt = $nextAttempts[$definition.Name]
            if ($null -ne $nextAttempt -and $now -lt $nextAttempt) {
                $workerStates.Add([pscustomobject]@{
                    queue = $definition.Queue
                    status = 'restart-wait'
                    processId = $null
                })
                continue
            }

            Write-SupervisorEvent -Level 'WARN' -Message "$($definition.Label) is not running; starting it."

            try {
                $process = Start-QueueWorker `
                    -Definition $definition `
                    -PhpPath $php `
                    -EnvironmentVariables $environment
                $failureCounts.Remove($definition.Name)
                $nextAttempts.Remove($definition.Name)
                Write-SupervisorEvent -Level 'INFO' -Message "$($definition.Label) restarted with PID $($process.Id)."
                $workerStates.Add([pscustomobject]@{
                    queue = $definition.Queue
                    status = 'restarted'
                    processId = $process.Id
                })
            }
            catch {
                $previousFailureCount = if ($failureCounts.ContainsKey($definition.Name)) {
                    [int]$failureCounts[$definition.Name]
                }
                else {
                    0
                }
                $failureCount = 1 + $previousFailureCount
                $failureCounts[$definition.Name] = $failureCount
                $retrySeconds = [Math]::Min(60, $IntervalSeconds * [Math]::Pow(2, $failureCount - 1))
                $nextAttempts[$definition.Name] = $now.AddSeconds($retrySeconds)
                Write-SupervisorEvent -Level 'ERROR' -Message "$($definition.Label) restart failed; retrying in $retrySeconds seconds. $($_.Exception.Message)"
                $workerStates.Add([pscustomobject]@{
                    queue = $definition.Queue
                    status = 'restart-failed'
                    processId = $null
                })
            }
        }

        @{
            processId = $PID
            checkedAt = (Get-Date).ToUniversalTime().ToString('o')
            intervalSeconds = $IntervalSeconds
            workers = $workerStates
        } | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $heartbeatPath -Encoding utf8

        if ($Once) {
            break
        }

        Start-Sleep -Seconds $IntervalSeconds
    }
}
catch {
    Write-SupervisorEvent -Level 'ERROR' -Message "Worker supervisor stopped unexpectedly. $($_.Exception.Message)"
    throw
}
finally {
    if ($Once) {
        Write-SupervisorEvent -Level 'INFO' -Message 'Single supervisor check completed.'
    }
}

[CmdletBinding()]
param(
    [ValidateRange(2, 300)][int]$IntervalSeconds = 30,
    [switch]$Once
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'runtime-common.ps1')
. (Join-Path $PSScriptRoot 'lib/worker-supervision.ps1')

Initialize-RuntimeDirectory
$php = Resolve-Php85
$state = New-WorkerSupervisionState
$heartbeatPath = Join-Path $script:RuntimeLogDirectory 'worker-supervisor-heartbeat.json'
Write-WorkerSupervisorEvent -Level 'INFO' -Message "Worker supervisor started with PID $PID."

do {
    $result = Invoke-WorkerSupervisionCycle -State $state -PhpPath $php -IntervalSeconds $IntervalSeconds
    if (-not $result.shouldContinue) { break }

    try {
        @{
            processId = $PID
            checkedAt = (Get-Date).ToUniversalTime().ToString('o')
            intervalSeconds = $IntervalSeconds
            databaseStatus = $result.databaseStatus
            workers = $result.workers
            error = $result.error
        } | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $heartbeatPath -Encoding utf8
    }
    catch {
        Write-WorkerSupervisorEvent -Level 'ERROR' -Message "Supervisor heartbeat could not be written; retrying next check. $($_.Exception.Message)"
    }

    if (-not $Once) { Start-Sleep -Seconds $IntervalSeconds }
} while (-not $Once)

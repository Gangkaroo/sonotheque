[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'runtime-common.ps1')
. (Join-Path $PSScriptRoot 'lib/worker-supervision.ps1')
$script:NativePostgresProbe = (Get-Command Get-PostgresStatus).ScriptBlock

$script:TestCount = 0
function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { throw $Message }
}
function Test-Case {
    param([string]$Name, [scriptblock]$Body)
    Reset-SupervisionMocks
    & $Body
    $script:TestCount++
    Write-Output "PASS $Name"
}
function Reset-SupervisionMocks {
    $script:Managed = @{}
    $script:External = @{}
    $script:Starts = [System.Collections.Generic.List[string]]::new()
    $script:Events = [System.Collections.Generic.List[string]]::new()
    $script:FailStarts = @{}
    $script:DatabaseStatus = 'Healthy'
    $script:DatabaseThrows = $false
    $script:ModeUnreadable = $false
    $script:StateExists = $true
    $script:BrokerResult = 0
}
function Get-RuntimeModeState {
    if ($script:ModeUnreadable -or -not $script:StateExists) { return $null }
    return [pscustomobject]@{ mode = 'local'; frontendHost = '127.0.0.1' }
}
function Get-BackendRuntimeEnvironment { param($Mode, $FrontendHost) return @{ TEST = 'true' } }
function Get-PostgresStatus {
    if ($script:DatabaseThrows) { throw 'Docker temporarily unavailable' }
    return $script:DatabaseStatus
}
function Get-ManagedProcess { param($Name) return $script:Managed[$Name] }
function Find-ExternalQueueWorker { param($Queue) return $script:External[$Queue] }
function Find-ExternalScheduler { return $script:External['scheduler'] }
function Write-WorkerSupervisorEvent { param($Level, $Message) $script:Events.Add("$Level $Message") }
function Start-FakeService {
    param($Name)
    $script:Starts.Add($Name)
    if ($script:FailStarts.ContainsKey($Name)) { throw 'Transient process startup failure' }
    $process = [pscustomobject]@{ Id = 100 + $script:Starts.Count }
    $script:Managed[$Name] = $process
    return $process
}
function Start-QueueWorker { param($Definition, $PhpPath, $EnvironmentVariables) return Start-FakeService $Definition.Name }
function Start-RuntimeScheduler { param($PhpPath, $EnvironmentVariables) return Start-FakeService 'scheduler' }
function Test-Path {
    param($LiteralPath)
    if ($LiteralPath -eq $script:RuntimeModeStatePath) { return $script:StateExists }
    return Microsoft.PowerShell.Management\Test-Path -LiteralPath $LiteralPath
}

Test-Case 'healthy and busy services are never restarted' {
    foreach ($name in @('queue-default', 'queue-scans', 'queue-analysis', 'scheduler')) {
        $script:Managed[$name] = [pscustomobject]@{ Id = 99 }
    }
    $result = Invoke-WorkerSupervisionCycle -State (New-WorkerSupervisionState) -PhpPath 'test-php'
    Assert-True ($result.workers.Count -eq 4) 'All queues and scheduler must be monitored.'
    Assert-True (@($result.workers | Where-Object status -ne 'managed').Count -eq 0) 'Busy services must remain managed.'
    Assert-True ($script:Starts.Count -eq 0) 'Running services were restarted.'
}

Test-Case 'outage waits without restart churn and recovery restarts all services' {
    $state = New-WorkerSupervisionState
    $script:DatabaseStatus = 'Unavailable'
    $first = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    $second = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True ($first.shouldContinue -and $second.shouldContinue) 'Outage stopped supervision.'
    Assert-True (@($second.workers | Where-Object status -ne 'waiting-for-database').Count -eq 0) 'Missing services must wait for the database.'
    Assert-True ($script:Starts.Count -eq 0) 'Services were launched against an unavailable database.'
    Assert-True ($script:Events.Count -eq 1) 'Unchanged outage must not repeat log messages.'
    $script:DatabaseStatus = 'Healthy'
    $recovered = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True (@($recovered.workers | Where-Object status -eq 'restarted').Count -eq 4) 'All services must recover.'
    $null = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True ($script:Starts.Count -eq 4) 'Recovery created duplicate workers.'
}

Test-Case 'outage does not terminate existing services' {
    $script:DatabaseStatus = 'Stopped'
    $script:Managed['queue-analysis'] = [pscustomobject]@{ Id = 77 }
    $result = Invoke-WorkerSupervisionCycle -State (New-WorkerSupervisionState) -PhpPath 'test-php'
    Assert-True (($result.workers | Where-Object queue -eq 'analysis').processId -eq 77) 'Existing analysis was interrupted.'
    Assert-True ($script:Starts.Count -eq 0) 'Unexpected service startup during outage.'
}

Test-Case 'externally managed workers and scheduler are left alone' {
    foreach ($queue in @('default', 'scans', 'analysis', 'scheduler')) {
        $script:External[$queue] = [pscustomobject]@{ ProcessId = 88 }
    }
    $result = Invoke-WorkerSupervisionCycle -State (New-WorkerSupervisionState) -PhpPath 'test-php'
    Assert-True (@($result.workers | Where-Object status -eq 'external').Count -eq 4) 'External ownership was lost.'
    Assert-True ($script:Starts.Count -eq 0) 'External services were duplicated.'
}

Test-Case 'one failed launch does not block other queues and uses bounded backoff' {
    $state = New-WorkerSupervisionState
    $script:FailStarts['queue-default'] = $true
    $first = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True (@($first.workers | Where-Object status -eq 'restarted').Count -eq 3) 'One failure blocked other services.'
    $second = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True (($second.workers | Where-Object queue -eq 'default').status -eq 'restart-wait') 'Backoff was skipped.'
    $state.NextAttempts['queue-default'] = (Get-Date).AddSeconds(-1)
    $null = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    $remaining = ($state.NextAttempts['queue-default'] - (Get-Date)).TotalSeconds
    Assert-True ($remaining -gt 55 -and $remaining -le 60) 'Backoff must be capped at 60 seconds.'
    $script:FailStarts.Clear()
    $state.NextAttempts['queue-default'] = (Get-Date).AddSeconds(-1)
    $recovered = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True (($recovered.workers | Where-Object queue -eq 'default').status -eq 'restarted') 'Failed worker never recovered.'
}

Test-Case 'Docker inspection errors cannot stop the supervisor' {
    $state = New-WorkerSupervisionState
    $script:DatabaseThrows = $true
    $result = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True ($result.shouldContinue -and $result.error) 'Inspection error stopped supervision.'
    $script:DatabaseThrows = $false
    $result = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True ($result.workers.Count -eq 4) 'Supervisor did not recover from inspection failure.'
}

Test-Case 'a transient runtime-state read failure is retried, not treated as shutdown' {
    $state = New-WorkerSupervisionState
    $script:ModeUnreadable = $true
    $result = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True ($result.shouldContinue -and $result.error) 'Unreadable state stopped supervision.'
    $script:ModeUnreadable = $false
    $result = Invoke-WorkerSupervisionCycle -State $state -PhpPath 'test-php'
    Assert-True ($result.workers.Count -eq 4) 'State-read recovery failed.'
}

Test-Case 'deliberately removed runtime state stops supervision' {
    $script:StateExists = $false
    $result = Invoke-WorkerSupervisionCycle -State (New-WorkerSupervisionState) -PhpPath 'test-php'
    Assert-True (-not $result.shouldContinue) 'Supervisor ignored deliberate shutdown.'
    Assert-True ($script:Starts.Count -eq 0) 'Shutdown relaunched services.'
}

# Broker tests record commands only; no process or real worker is launched.
function Remove-StaleProcessState { param($Name) }
function Move-ExistingRuntimeLog { param($Path) }
function Start-Sleep { param($Milliseconds, $Seconds) }
function New-CimInstance { param($ClassName, [switch]$ClientOnly, $Property) return [pscustomobject]$Property }
function Invoke-CimMethod {
    param($ClassName, $MethodName, $Arguments)
    $script:BrokerArguments = $Arguments
    return [pscustomobject]@{ ReturnValue = $script:BrokerResult; ProcessId = $PID }
}

$temporaryDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ("Sonotheque supervisor's test-" + [guid]::NewGuid().ToString('N'))
$previousProbeState = $env:SONOTHEQUE_TEST_DOCKER_STATE
$fakeProcessName = 'FakeDocker-' + [guid]::NewGuid().ToString('N')
try {
    New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null
    $script:FakeDockerPath = Join-Path $temporaryDirectory "$fakeProcessName.exe"
    Add-Type -Path (Join-Path $PSScriptRoot 'tests/FakeDockerHealthProbe.cs') `
        -OutputAssembly $script:FakeDockerPath -OutputType ConsoleApplication
    function Get-Command {
        param($Name)
        if ($Name -eq 'docker') { return [pscustomobject]@{ Source = $script:FakeDockerPath } }
        return Microsoft.PowerShell.Core\Get-Command -Name $Name
    }
    Test-Case 'native health probe handles healthy, stopped, and starting containers' {
        foreach ($entry in @(
            @{ State = 'healthy'; Expected = 'Healthy' },
            @{ State = 'stopped'; Expected = 'Stopped' },
            @{ State = 'starting'; Expected = 'Running (starting)' }
        )) {
            $env:SONOTHEQUE_TEST_DOCKER_STATE = $entry.State
            $status = & $script:NativePostgresProbe -TimeoutSeconds 2
            Assert-True ($status -eq $entry.Expected) "Unexpected probe result: $status"
        }
    }
    Test-Case 'native health probe tolerates Docker failures' {
        $env:SONOTHEQUE_TEST_DOCKER_STATE = 'failure'
        $status = & $script:NativePostgresProbe -TimeoutSeconds 2
        Assert-True ($status -eq 'Unavailable') 'Docker error escaped the health probe.'
    }
    Test-Case 'native health probe terminates a hung Docker client within its timeout' {
        $env:SONOTHEQUE_TEST_DOCKER_STATE = 'timeout'
        $watch = [System.Diagnostics.Stopwatch]::StartNew()
        $status = & $script:NativePostgresProbe -TimeoutSeconds 1
        $watch.Stop()
        Assert-True ($status -eq 'Unavailable') 'Hung client was treated as healthy.'
        Assert-True ($watch.Elapsed.TotalSeconds -lt 4) 'Docker probe exceeded its timeout.'
        Assert-True ($null -eq (Get-Process -Name $fakeProcessName -ErrorAction SilentlyContinue)) 'Hung test client was left running.'
    }
    $script:RuntimeLogDirectory = $temporaryDirectory
    $script:RepositoryRoot = $temporaryDirectory
    Test-Case 'supervisor uses a hidden Windows broker and quotes paths safely' {
        $process = Start-WorkerSupervisor
        Assert-True ($process.Id -eq $PID) 'Broker result was not tracked.'
        Assert-True ($script:BrokerArguments.ProcessStartupInformation.ShowWindow -eq 0) 'Supervisor window must be hidden.'
        $encoded = ($script:BrokerArguments.CommandLine -split ' ')[-1]
        $command = [System.Text.Encoding]::Unicode.GetString([Convert]::FromBase64String($encoded))
        Assert-True ($command.Contains("supervisor''s test-")) 'Apostrophe in the path was not escaped.'
        Assert-True ($command.Contains('supervise-workers.ps1')) 'Wrong supervisor entry point.'
        $ownership = Get-Content -LiteralPath (Get-ProcessStatePath -Name 'worker-supervisor') -Raw | ConvertFrom-Json
        Assert-True ($ownership.processId -eq $PID) 'Managed ownership record is missing.'
    }
    Test-Case 'broker failures are reported instead of silently using a terminal-owned process' {
        $script:BrokerResult = 5
        $message = $null
        try { Start-WorkerSupervisor | Out-Null } catch { $message = $_.Exception.Message }
        Assert-True ($message -like '*code 5*') 'Broker failure was hidden.'
    }
}
finally {
    $env:SONOTHEQUE_TEST_DOCKER_STATE = $previousProbeState
    Get-Process -Name $fakeProcessName -ErrorAction SilentlyContinue | Stop-Process -Force
    $resolved = [System.IO.Path]::GetFullPath($temporaryDirectory)
    $temporaryRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
    if (-not $resolved.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Refusing to clean up a test directory outside the temporary folder.'
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}

Write-Output "$script:TestCount worker-supervision tests passed."

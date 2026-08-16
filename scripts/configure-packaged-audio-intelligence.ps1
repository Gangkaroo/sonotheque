[CmdletBinding()]
param(
    [string]$ModelPath,

    [ValidateSet('Cpu', 'Cuda')]
    [string]$Accelerator = 'Cpu',

    [switch]$Disable
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

function Select-AudioIntelligenceModel {
    Add-Type -AssemblyName System.Windows.Forms
    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Title = 'Select the reviewed Discogs EffNet model'
    $dialog.Filter = 'TensorFlow model (*.pb)|*.pb|All files (*.*)|*.*'
    $dialog.Multiselect = $false
    if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
        throw 'Audio Intelligence configuration was cancelled.'
    }

    return $dialog.FileName
}

function Invoke-DockerBuild {
    param(
        [Parameter(Mandatory)][string]$Image,
        [string]$Dockerfile
    )

    $arguments = @('build')
    if (-not [string]::IsNullOrWhiteSpace($Dockerfile)) {
        $arguments += @('--file', $Dockerfile)
    }
    $arguments += @('--tag', $Image, (Join-Path $script:RepositoryRoot 'audio-intelligence'))
    & docker @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Building the Audio Intelligence image failed: $Image"
    }
}

function Restart-PackagedAudioServices {
    param([Parameter(Mandatory)][string]$Worker)

    $inactiveWorker = if ($Worker -eq 'queue-analysis-ai') {
        'queue-analysis'
    }
    else {
        'queue-analysis-ai'
    }
    Invoke-PackagedCompose -Arguments @('stop', $inactiveWorker) -AllowExampleEnvironment

    $runningWeb = & docker compose `
        --env-file $script:PackagedEnvironmentPath `
        -f $script:PackagedComposeFile `
        ps --status running -q web
    if (-not [string]::IsNullOrWhiteSpace([string]($runningWeb | Select-Object -First 1))) {
        Invoke-PackagedCompose -Arguments @(
            'up', '-d', '--build', '--force-recreate',
            'backend', 'queue-default', 'queue-scans', $Worker, 'scheduler', 'web'
        )
    }
}

try {
    Assert-PackagedDockerAvailable
    Initialize-PackagedEnvironment
    Sync-PackagedMusicRootOverride

    if ($Disable) {
        Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_DRIVER' -Value 'none'
        Invoke-PackagedCompose -Arguments @('stop', 'queue-analysis-ai') -AllowExampleEnvironment
        $analyzerContainers = @(& docker ps -aq --filter 'label=sonotheque.audio-analyzer=true')
        if ($analyzerContainers.Count -gt 0) {
            & docker rm --force @analyzerContainers | Out-Null
        }
        Restart-PackagedAudioServices -Worker 'queue-analysis'
        Write-Host 'Packaged Audio Intelligence is disabled. Existing analysis results were retained.'
        exit 0
    }

    if ([string]::IsNullOrWhiteSpace($ModelPath)) {
        $ModelPath = Select-AudioIntelligenceModel
    }
    if (-not (Test-Path -LiteralPath $ModelPath -PathType Leaf)) {
        throw "The selected model file does not exist: $ModelPath"
    }
    $resolvedModel = (Resolve-Path -LiteralPath $ModelPath).Path
    if ([System.IO.Path]::GetExtension($resolvedModel) -ne '.pb') {
        throw 'Select a reviewed TensorFlow .pb model file.'
    }

    $cpuImage = 'sonotheque-audio-intelligence:analysis'
    $cudaImage = 'sonotheque-audio-intelligence:cuda'
    Write-Host 'Building the CPU analyzer image...'
    Invoke-DockerBuild -Image $cpuImage
    if ($Accelerator -eq 'Cuda') {
        Write-Host 'Building the optional CUDA analyzer image...'
        Invoke-DockerBuild `
            -Image $cudaImage `
            -Dockerfile (Join-Path $script:RepositoryRoot 'audio-intelligence\Dockerfile.cuda')
    }

    $selectedImage = if ($Accelerator -eq 'Cuda') { $cudaImage } else { $cpuImage }
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_MODEL_DIRECTORY' -Value (Split-Path -Parent $resolvedModel)
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_MODEL_FILENAME' -Value (Split-Path -Leaf $resolvedModel)
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_DOCKER_IMAGE' -Value $selectedImage
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_BENCHMARK_CPU_IMAGE' -Value $cpuImage
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_BENCHMARK_CUDA_IMAGE' -Value $cudaImage
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_ACCELERATOR' -Value $Accelerator.ToLowerInvariant()
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_PERSISTENT' -Value 'true'
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_MOUNT_SOURCE_CONTAINER' -Value 'self'
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_HEALTH_VIA_QUEUE' -Value 'true'
    Set-PackagedEnvValue -Name 'AUDIO_INTELLIGENCE_DRIVER' -Value 'essentia_docker'

    Restart-PackagedAudioServices -Worker 'queue-analysis-ai'

    Write-Host ''
    Write-Host "Packaged Audio Intelligence is configured for $Accelerator."
    Write-Host "Model: $resolvedModel"
    Write-Host 'Open Settings > Audio Intelligence and run the analyzer check before analysis.'
}
catch {
    Write-Error $_
    exit 1
}

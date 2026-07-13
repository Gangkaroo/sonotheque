[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string[]]$RootMap,

    [switch]$Force,
    [switch]$NoRestart
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

function Invoke-Checked {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$ArgumentList
    )

    & $FilePath @ArgumentList
    if ($LASTEXITCODE -ne 0) {
        throw "$FilePath exited with code $LASTEXITCODE."
    }
}

function Invoke-CheckedOutput {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$ArgumentList
    )

    $output = & $FilePath @ArgumentList
    if ($LASTEXITCODE -ne 0) {
        throw "$FilePath exited with code $LASTEXITCODE."
    }

    return $output
}

function ConvertTo-SqlLiteral {
    param([Parameter(Mandatory)][AllowEmptyString()][string]$Value)

    return "'" + $Value.Replace("'", "''") + "'"
}

function Get-Sha256Hex {
    param([Parameter(Mandatory)][string]$Value)

    $algorithm = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Value.ToLowerInvariant())
        return [BitConverter]::ToString($algorithm.ComputeHash($bytes)).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $algorithm.Dispose()
    }
}

function ConvertTo-RootMappings {
    param([Parameter(Mandatory)][string[]]$Mappings)

    foreach ($mapping in $Mappings) {
        $separator = $mapping.IndexOf('=')
        if ($separator -lt 1 -or $separator -eq ($mapping.Length - 1)) {
            throw "Root mapping '$mapping' must use the format 'host-path=/music/root-n'."
        }

        $source = $mapping.Substring(0, $separator).Trim()
        $target = $mapping.Substring($separator + 1).Trim()
        if ($source -eq '' -or $target -eq '') {
            throw "Root mapping '$mapping' must contain both source and target paths."
        }

        if (-not $target.StartsWith('/music/')) {
            throw "Packaged target path '$target' must live below /music/."
        }

        [pscustomobject]@{
            Source = $source
            Target = $target
            TargetHash = Get-Sha256Hex -Value $target
        }
    }
}

function Invoke-PostgresScalar {
    param(
        [Parameter(Mandatory)][string]$Container,
        [Parameter(Mandatory)][string]$Password,
        [Parameter(Mandatory)][string]$User,
        [Parameter(Mandatory)][string]$Database,
        [Parameter(Mandatory)][string]$Sql
    )

    $output = Invoke-CheckedOutput -FilePath 'docker' -ArgumentList @(
        'exec',
        '-e',
        "PGPASSWORD=$Password",
        $Container,
        'psql',
        '-U',
        $User,
        '-d',
        $Database,
        '-v',
        'ON_ERROR_STOP=1',
        '-t',
        '-A',
        '-c',
        $Sql
    )

    return [string]($output | Select-Object -First 1)
}

function Assert-RootMappingsCoverDevelopmentRoots {
    param(
        [Parameter(Mandatory)][object[]]$Mappings,
        [Parameter(Mandatory)][string]$Password,
        [Parameter(Mandatory)][string]$User,
        [Parameter(Mandatory)][string]$Database
    )

    $developmentRootCount = [int](Invoke-PostgresScalar `
        -Container 'sonotheque-postgres' `
        -Password $Password `
        -User $User `
        -Database $Database `
        -Sql 'SELECT count(*) FROM library_roots;')

    if ($developmentRootCount -ne $Mappings.Count) {
        throw "The development database contains $developmentRootCount library root(s), but $($Mappings.Count) RootMap value(s) were provided."
    }

    foreach ($mapping in $Mappings) {
        $matchingRootCount = [int](Invoke-PostgresScalar `
            -Container 'sonotheque-postgres' `
            -Password $Password `
            -User $User `
            -Database $Database `
            -Sql ('SELECT count(*) FROM library_roots WHERE path = ' + (ConvertTo-SqlLiteral -Value $mapping.Source) + ';'))

        if ($matchingRootCount -ne 1) {
            throw "RootMap source '$($mapping.Source)' matches $matchingRootCount development library root(s). Each source must match exactly one root."
        }
    }
}

try {
    if (-not $Force) {
        throw 'This command replaces the packaged database. Re-run with -Force after checking the RootMap values.'
    }

    Assert-PackagedDockerAvailable
    if (-not (Test-Path -LiteralPath $script:PackagedEnvironmentPath)) {
        throw '.env.packaged does not exist. Start packaged mode once before migrating data.'
    }

    $rootMappings = @(ConvertTo-RootMappings -Mappings $RootMap)
    $backendEnvironmentPath = Join-Path $script:RepositoryRoot 'backend\.env'
    if (-not (Test-Path -LiteralPath $backendEnvironmentPath)) {
        throw 'backend/.env does not exist, so the current development database settings cannot be read.'
    }

    $devDatabase = Get-PackagedEnvValue -Path $backendEnvironmentPath -Name 'DB_DATABASE'
    $devUser = Get-PackagedEnvValue -Path $backendEnvironmentPath -Name 'DB_USERNAME'
    $devPassword = Get-PackagedEnvValue -Path $backendEnvironmentPath -Name 'DB_PASSWORD'
    $devDatabase = if ([string]::IsNullOrWhiteSpace($devDatabase)) { 'sonotheque' } else { $devDatabase }
    $devUser = if ([string]::IsNullOrWhiteSpace($devUser)) { 'sonotheque' } else { $devUser }
    $devPassword = if ($null -eq $devPassword) { '' } else { $devPassword }

    $packagedDatabase = Get-PackagedEnvValue -Name 'POSTGRES_DB'
    $packagedUser = Get-PackagedEnvValue -Name 'POSTGRES_USER'
    $packagedPassword = Get-PackagedEnvValue -Name 'POSTGRES_PASSWORD'
    $packagedDatabase = if ([string]::IsNullOrWhiteSpace($packagedDatabase)) { 'sonotheque' } else { $packagedDatabase }
    $packagedUser = if ([string]::IsNullOrWhiteSpace($packagedUser)) { 'sonotheque' } else { $packagedUser }
    $packagedPassword = if ($null -eq $packagedPassword) { '' } else { $packagedPassword }

    $devAppKey = Get-PackagedEnvValue -Path $backendEnvironmentPath -Name 'APP_KEY'
    if ([string]::IsNullOrWhiteSpace($devAppKey)) {
        throw 'backend/.env does not contain APP_KEY. The packaged app must use the same APP_KEY to decrypt existing settings.'
    }

    Set-PackagedEnvValue -Name 'APP_KEY' -Value $devAppKey
    Write-Host 'Copied APP_KEY from backend/.env to .env.packaged.'

    Write-Host 'Starting source and target PostgreSQL containers...'
    Push-Location $script:RepositoryRoot
    try {
        Invoke-Checked -FilePath 'docker' -ArgumentList @('compose', '-f', 'compose.yaml', 'up', '-d', 'postgres')
    }
    finally {
        Pop-Location
    }

    Invoke-PackagedCompose -Arguments @('up', '-d', 'postgres')

    Write-Host 'Checking root mappings against the development database...'
    Assert-RootMappingsCoverDevelopmentRoots `
        -Mappings $rootMappings `
        -Password $devPassword `
        -User $devUser `
        -Database $devDatabase

    Write-Host 'Stopping packaged app services before restore...'
    Invoke-PackagedCompose -Arguments @('stop', 'web', 'backend', 'queue', 'scheduler') -AllowExampleEnvironment

    $migrationDirectory = Join-Path $script:RepositoryRoot 'runtime-logs\migration'
    New-Item -ItemType Directory -Path $migrationDirectory -Force | Out-Null
    $timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $dumpPath = Join-Path $migrationDirectory "dev-to-packaged-$timestamp.dump"
    $containerDumpPath = '/tmp/sonotheque-dev.dump'

    Write-Host 'Dumping development database...'
    Invoke-Checked -FilePath 'docker' -ArgumentList @(
        'exec',
        '-e',
        "PGPASSWORD=$devPassword",
        'sonotheque-postgres',
        'pg_dump',
        '-U',
        $devUser,
        '-d',
        $devDatabase,
        '-Fc',
        '-f',
        $containerDumpPath
    )
    Invoke-Checked -FilePath 'docker' -ArgumentList @(
        'cp',
        "sonotheque-postgres:$containerDumpPath",
        $dumpPath
    )

    $packagedPostgresContainer = (& docker compose --env-file $script:PackagedEnvironmentPath -f $script:PackagedComposeFile ps -q postgres | Select-Object -First 1).Trim()
    if ([string]::IsNullOrWhiteSpace($packagedPostgresContainer)) {
        throw 'Packaged PostgreSQL container was not found.'
    }

    Write-Host 'Restoring dump into packaged database...'
    Invoke-Checked -FilePath 'docker' -ArgumentList @(
        'cp',
        $dumpPath,
        "${packagedPostgresContainer}:$containerDumpPath"
    )
    Invoke-Checked -FilePath 'docker' -ArgumentList @(
        'exec',
        '-e',
        "PGPASSWORD=$packagedPassword",
        $packagedPostgresContainer,
        'pg_restore',
        '--clean',
        '--if-exists',
        '--no-owner',
        '--no-privileges',
        '-U',
        $packagedUser,
        '-d',
        $packagedDatabase,
        $containerDumpPath
    )

    Write-Host 'Remapping library roots for container paths...'
    foreach ($mapping in $rootMappings) {
        $sql = @(
            'UPDATE library_roots',
            'SET path = ' + (ConvertTo-SqlLiteral -Value $mapping.Target) + ',',
            '    path_hash = ' + (ConvertTo-SqlLiteral -Value $mapping.TargetHash),
            'WHERE path = ' + (ConvertTo-SqlLiteral -Value $mapping.Source) + ';'
        ) -join ' '

        Invoke-Checked -FilePath 'docker' -ArgumentList @(
            'exec',
            '-e',
            "PGPASSWORD=$packagedPassword",
            $packagedPostgresContainer,
            'psql',
            '-U',
            $packagedUser,
            '-d',
            $packagedDatabase,
            '-v',
            'ON_ERROR_STOP=1',
            '-c',
            $sql
        )
    }

    Write-Host ''
    Write-Host 'Packaged library roots after remap:'
    Invoke-Checked -FilePath 'docker' -ArgumentList @(
        'exec',
        '-e',
        "PGPASSWORD=$packagedPassword",
        $packagedPostgresContainer,
        'psql',
        '-U',
        $packagedUser,
        '-d',
        $packagedDatabase,
        '-c',
        'SELECT id, name, path, enabled FROM library_roots ORDER BY id;'
    )

    if (-not $NoRestart) {
        Write-Host 'Starting packaged app services...'
        Invoke-PackagedCompose -Arguments @('up', '-d', 'backend', 'queue', 'scheduler', 'web')
    }

    Write-Host ''
    Write-Host "Migration dump retained at: $dumpPath"
    Write-Host 'Run a rescan in packaged mode after verifying the root paths.'
}
catch {
    Write-Error $_
    exit 1
}

[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Tag
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = Split-Path -Parent $PSScriptRoot

if ($Tag -notmatch '^v(?<version>\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$') {
    throw "Release tag '$Tag' must use the form v1.2.3 or v1.2.3-prerelease."
}

$version = $Matches.version
$declaredVersion = (Get-Content -LiteralPath (Join-Path $repositoryRoot 'VERSION') -Raw).Trim()
if ($version -ne $declaredVersion) {
    throw "Release tag version '$version' does not match VERSION '$declaredVersion'."
}

$frontendPackage = Get-Content -LiteralPath (Join-Path $repositoryRoot 'frontend\package.json') -Raw | ConvertFrom-Json
if ($version -ne [string] $frontendPackage.version) {
    throw "Release tag version '$version' does not match frontend/package.json '$($frontendPackage.version)'."
}
$frontendLockJson = Get-Content -LiteralPath (Join-Path $repositoryRoot 'frontend\package-lock.json') -Raw
if ($PSVersionTable.PSVersion.Major -ge 6) {
    $frontendLock = $frontendLockJson | ConvertFrom-Json -AsHashtable
} else {
    Add-Type -AssemblyName System.Web.Extensions
    $json = New-Object System.Web.Script.Serialization.JavaScriptSerializer
    $frontendLock = $json.DeserializeObject($frontendLockJson)
}
if ($version -ne [string] $frontendLock['version'] -or
    $version -ne [string] $frontendLock['packages']['']['version']) {
    throw "Release tag version '$version' does not match frontend/package-lock.json."
}

$changelog = Get-Content -LiteralPath (Join-Path $repositoryRoot 'CHANGELOG.md')
$headingPattern = '^##\s+' + [regex]::Escape($version) + '(?:\s+-.*)?$'
if (-not ($changelog | Where-Object { $_ -match $headingPattern })) {
    throw "CHANGELOG.md has no section for version $version."
}

if (-not [string]::IsNullOrWhiteSpace($env:GITHUB_OUTPUT)) {
    Add-Content -LiteralPath $env:GITHUB_OUTPUT -Value "version=$version" -Encoding utf8
}

Write-Host "Release version verified: $Tag"

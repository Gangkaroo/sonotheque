[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Version,
    [Parameter(Mandatory)][string]$OutputPath
)

$ErrorActionPreference = 'Stop'
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$lines = Get-Content -LiteralPath (Join-Path $repositoryRoot 'CHANGELOG.md')
$headingPattern = '^##\s+' + [regex]::Escape($Version) + '(?:\s+-.*)?$'
$start = -1
for ($index = 0; $index -lt $lines.Count; $index++) {
    if ($lines[$index] -match $headingPattern) {
        $start = $index + 1
        break
    }
}
if ($start -lt 0) {
    throw "CHANGELOG.md has no section for version $Version."
}

$notes = [System.Collections.Generic.List[string]]::new()
for ($index = $start; $index -lt $lines.Count; $index++) {
    if ($lines[$index] -match '^##\s+') {
        break
    }
    $notes.Add($lines[$index])
}
while ($notes.Count -gt 0 -and [string]::IsNullOrWhiteSpace($notes[0])) {
    $notes.RemoveAt(0)
}
while ($notes.Count -gt 0 -and [string]::IsNullOrWhiteSpace($notes[$notes.Count - 1])) {
    $notes.RemoveAt($notes.Count - 1)
}
if ($notes.Count -eq 0) {
    throw "The CHANGELOG.md section for version $Version is empty."
}

$resolvedOutputPath = if ([System.IO.Path]::IsPathRooted($OutputPath)) {
    $OutputPath
} else {
    Join-Path $repositoryRoot $OutputPath
}
$outputDirectory = Split-Path -Parent $resolvedOutputPath
if (-not [string]::IsNullOrWhiteSpace($outputDirectory)) {
    New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
}
Set-Content -LiteralPath $resolvedOutputPath -Value $notes -Encoding utf8
Write-Host "Created release notes: $resolvedOutputPath"

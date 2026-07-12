[CmdletBinding()]
param(
    [string[]]$MusicRoot,
    [switch]$Replace
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'packaged-common.ps1')

function Select-PackagedMusicFolder {
    param([string]$Description = 'Select a folder containing music for Sonotheque.')

    Add-Type -AssemblyName System.Windows.Forms
    $dialog = New-Object System.Windows.Forms.FolderBrowserDialog
    $dialog.Description = $Description
    $dialog.ShowNewFolderButton = $false
    try {
        if ($dialog.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
            return $null
        }

        return $dialog.SelectedPath
    }
    finally {
        $dialog.Dispose()
    }
}

function Select-PackagedMusicFolders {
    param([string[]]$InitialRoots)

    Add-Type -AssemblyName System.Windows.Forms
    $roots = [System.Collections.Generic.List[string]]::new()
    foreach ($initialRoot in $InitialRoots) {
        $roots.Add($initialRoot)
    }

    do {
        $selectedRoot = Select-PackagedMusicFolder
        if ([string]::IsNullOrWhiteSpace($selectedRoot)) {
            if ($roots.Count -eq 0) {
                throw 'No music folder was selected. Configuration was cancelled.'
            }
            break
        }
        if ($selectedRoot -notin $roots) {
            $roots.Add($selectedRoot)
        }

        $answer = [System.Windows.Forms.MessageBox]::Show(
            'Would you like to add another music folder?',
            'Configure Sonotheque folders',
            [System.Windows.Forms.MessageBoxButtons]::YesNo,
            [System.Windows.Forms.MessageBoxIcon]::Question
        )
    } while ($answer -eq [System.Windows.Forms.DialogResult]::Yes)

    return @($roots)
}

try {
    $existingRoots = @(Get-PackagedMusicRoots)
    $roots = @($MusicRoot)

    if ($roots.Count -eq 0) {
        if ($existingRoots.Count -gt 0 -and -not $Replace) {
            Add-Type -AssemblyName System.Windows.Forms
            $mapping = for ($index = 0; $index -lt $existingRoots.Count; $index++) {
                "/music/root-$($index + 1)  <-  $($existingRoots[$index])"
            }
            $answer = [System.Windows.Forms.MessageBox]::Show(
                "Current folders:`n`n$($mapping -join "`n")`n`nYes: keep these mappings and add folders.`nNo: select a complete replacement list.`nCancel: make no changes.",
                'Configure Sonotheque folders',
                [System.Windows.Forms.MessageBoxButtons]::YesNoCancel,
                [System.Windows.Forms.MessageBoxIcon]::Information
            )
            if ($answer -eq [System.Windows.Forms.DialogResult]::Cancel) {
                exit 0
            }
            if ($answer -eq [System.Windows.Forms.DialogResult]::Yes) {
                $roots = Select-PackagedMusicFolders -InitialRoots $existingRoots
            }
            else {
                $roots = Select-PackagedMusicFolders -InitialRoots @()
            }
        }
        else {
            $roots = Select-PackagedMusicFolders -InitialRoots @()
        }
    }
    elseif (-not $Replace) {
        $roots = @($existingRoots + $roots)
    }

    $entries = @(Set-PackagedMusicRoots -MusicRoots $roots)
    Write-Host ''
    Write-Host 'Configured Sonotheque music mounts:'
    foreach ($entry in $entries) {
        Write-Host "  $($entry.containerPath) <- $($entry.hostPath)"
    }
    Write-Host ''
    Write-Host 'Start or restart Sonotheque to apply the new mounts.'
    Write-Host 'Existing catalog roots are not deleted automatically.'
}
catch {
    Write-Error $_
    exit 1
}

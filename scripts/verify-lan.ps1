[CmdletBinding()]
param(
    [ValidateSet('Development', 'Packaged')]
    [string]$Mode = 'Development'
)

$ErrorActionPreference = 'Stop'
$checks = [System.Collections.Generic.List[object]]::new()

function Add-Check {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][bool]$Passed,
        [Parameter(Mandatory)][string]$Details
    )

    $checks.Add([pscustomobject]@{
        Check = $Name
        Status = if ($Passed) { 'Pass' } else { 'Fail' }
        Details = $Details
    })
}

function Get-HttpStatusCode {
    param(
        [Parameter(Mandatory)][string]$Uri,
        [hashtable]$Headers = @{}
    )

    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $Uri -Headers $Headers -TimeoutSec 15
        return [int]$response.StatusCode
    }
    catch {
        if ($null -ne $_.Exception.Response -and $null -ne $_.Exception.Response.StatusCode) {
            return [int]$_.Exception.Response.StatusCode
        }

        throw
    }
}

function Get-FirewallCheck {
    param(
        [Parameter(Mandatory)][string]$Address,
        [Parameter(Mandatory)][int]$Port
    )

    try {
        $rules = @(Get-NetFirewallRule -Direction Inbound -Enabled True -ErrorAction Stop | Where-Object {
            $_.DisplayName -in @('Sonotheque LAN', 'Sonotheque Packaged LAN') -and
            $_.Action -eq 'Allow' -and
            $_.Profile -match 'Private'
        })

        foreach ($rule in $rules) {
            $portFilter = $rule | Get-NetFirewallPortFilter -ErrorAction Stop
            $addressFilter = $rule | Get-NetFirewallAddressFilter -ErrorAction Stop
            if ($portFilter.Protocol -eq 'TCP' -and
                $portFilter.LocalPort -contains [string]$Port -and
                $addressFilter.LocalAddress -contains $Address -and
                $addressFilter.RemoteAddress -contains 'LocalSubnet') {
                return [pscustomobject]@{
                    Available = $true
                    Passed = $true
                    Details = "$($rule.DisplayName): Private TCP $Port on $Address from LocalSubnet"
                }
            }
        }

        return [pscustomobject]@{
            Available = $true
            Passed = $false
            Details = 'No matching Private/LocalSubnet Sonotheque firewall rule was found.'
        }
    }
    catch {
        return [pscustomobject]@{
            Available = $false
            Passed = $false
            Details = 'Firewall inspection requires an elevated PowerShell window.'
        }
    }
}

try {
    if ($Mode -eq 'Development') {
        . (Join-Path $PSScriptRoot 'runtime-common.ps1')
        $runtime = Get-RuntimeModeState
        if ($null -eq $runtime -or $runtime.mode -ne 'lan') {
            throw 'The development runtime is not recorded as LAN mode. Start it with scripts/start.ps1 -Lan.'
        }

        $address = [string]$runtime.frontendHost
        $appUri = ([string]$runtime.frontendUrl).TrimEnd('/')
        $port = 5173
        $adminToken = Get-LanAdminToken
        $null = Resolve-LanIpv4Address -RequestedAddress $address
        $tokenSource = 'backend/.env'
    }
    else {
        . (Join-Path $PSScriptRoot 'packaged-common.ps1')
        $lanEnabled = Get-PackagedEnvValue -Name 'SONOTHEQUE_LAN_ENABLED'
        if ($lanEnabled -ne 'true') {
            throw 'The packaged runtime is not configured for LAN mode. Start it with scripts/start-packaged.ps1 -Lan.'
        }

        $appUri = (Get-PackagedEnvValue -Name 'APP_URL').TrimEnd('/')
        if ([string]::IsNullOrWhiteSpace($appUri)) {
            throw 'APP_URL is missing from .env.packaged.'
        }

        $parsedUri = [Uri]$appUri
        $address = $parsedUri.Host
        $port = $parsedUri.Port
        $adminToken = Get-PackagedEnvValue -Name 'SONOTHEQUE_ADMIN_TOKEN'
        if ([string]::IsNullOrWhiteSpace($adminToken) -or $adminToken.Length -lt 32) {
            throw 'Packaged LAN mode requires SONOTHEQUE_ADMIN_TOKEN with at least 32 characters.'
        }

        $null = Resolve-PackagedLanIpv4Address -RequestedAddress $address
        $tokenSource = '.env.packaged'
    }

    Add-Check -Name 'LAN address' -Passed $true -Details "$address is a private IPv4 address assigned to this computer"

    $listeners = [System.Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners()
    $listenerFound = @($listeners | Where-Object {
        $_.Port -eq $port -and $_.Address.ToString() -eq $address
    }).Count -gt 0
    Add-Check -Name 'LAN listener' -Passed $listenerFound -Details "Expected $address`:$port"

    $frontendStatus = Get-HttpStatusCode -Uri "$appUri/"
    Add-Check -Name 'Frontend' -Passed ($frontendStatus -eq 200) -Details "GET / returned HTTP $frontendStatus"

    $catalogStatus = Get-HttpStatusCode -Uri "$appUri/api/catalog/genres?page=1&itemsPerPage=1"
    Add-Check -Name 'Public catalog' -Passed ($catalogStatus -eq 200) -Details "GET /api/catalog/genres returned HTTP $catalogStatus"

    $anonymousStatus = Get-HttpStatusCode -Uri "$appUri/api/settings/access"
    Add-Check -Name 'Anonymous administration' -Passed ($anonymousStatus -eq 403) -Details "GET /api/settings/access returned HTTP $anonymousStatus without a token"

    $invalidStatus = Get-HttpStatusCode `
        -Uri "$appUri/api/settings/access" `
        -Headers @{ 'X-Sonotheque-Admin-Token' = 'invalid-lan-verification-token' }
    Add-Check -Name 'Invalid admin token' -Passed ($invalidStatus -eq 403) -Details "GET /api/settings/access returned HTTP $invalidStatus"

    $authorizedStatus = Get-HttpStatusCode `
        -Uri "$appUri/api/settings/access" `
        -Headers @{ 'X-Sonotheque-Admin-Token' = $adminToken }
    Add-Check -Name 'Valid admin token' -Passed ($authorizedStatus -eq 200) -Details "GET /api/settings/access returned HTTP $authorizedStatus"

    $firewall = Get-FirewallCheck -Address $address -Port $port
    if ($firewall.Available) {
        Add-Check -Name 'Windows Firewall' -Passed $firewall.Passed -Details $firewall.Details
    }

    Write-Host ''
    Write-Host "Sonotheque $Mode LAN verification"
    Write-Host "App URL: $appUri/"
    $checks | Format-Table -AutoSize

    if (-not $firewall.Available) {
        Write-Warning $firewall.Details
    }

    Write-Host 'Second-device checklist:'
    Write-Host '  1. Open the app URL from a device on the same private network.'
    Write-Host '  2. Browse the catalog, open artwork, and play or seek a track.'
    Write-Host '  3. Confirm protected Settings tabs are disabled without a token.'
    Write-Host "  4. Enter the admin token from $tokenSource in Settings > Security and verify it."
    Write-Host '  5. Confirm the protected Settings tabs load, then clear the token and confirm they lock again.'

    if (@($checks | Where-Object { $_.Status -eq 'Fail' }).Count -gt 0) {
        exit 1
    }
}
catch {
    Write-Error $_
    exit 1
}

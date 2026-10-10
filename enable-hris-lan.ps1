[CmdletBinding(SupportsShouldProcess)]
param()

$ErrorActionPreference = 'Stop'

$hrisEnvPath = Join-Path $PSScriptRoot '.env'
$hrisEnvText = [System.IO.File]::ReadAllText($hrisEnvPath)
$hrisUrlMatch = [regex]::Match($hrisEnvText, '(?m)^APP_URL=(.+)$')
if (-not $hrisUrlMatch.Success) {
    throw 'APP_URL must be configured in the backend .env.'
}

$hrisAppUrl = [uri]($hrisUrlMatch.Groups[1].Value.Trim().Trim('"', "'"))
$hrisAddress = $null
if (-not [System.Net.IPAddress]::TryParse($hrisAppUrl.Host, [ref]$hrisAddress) -or
    $hrisAddress.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork -or
    [System.Net.IPAddress]::IsLoopback($hrisAddress) -or
    $hrisAddress.Equals([System.Net.IPAddress]::Any)) {
    throw 'APP_URL must use this PC''s LAN IPv4 address.'
}

$hrisInterface = @(Get-NetIPAddress -AddressFamily IPv4 -IPAddress $hrisAddress.ToString() -ErrorAction Stop)
if ($hrisInterface.Count -ne 1 -or $hrisInterface[0].AddressState -ne 'Preferred') {
    throw 'The configured LAN address is not active on this PC.'
}

$hrisRuleName = 'HRIS-LAN-TCP'
$hrisRuleSettings = @{
    Name = $hrisRuleName
    Direction = 'Inbound'
    Action = 'Allow'
    Enabled = 'True'
    Profile = 'Any'
    Protocol = 'TCP'
    LocalPort = @(5002, 8002, 8082)
    LocalAddress = $hrisAddress.ToString()
    RemoteAddress = 'LocalSubnet'
    InterfaceAlias = $hrisInterface[0].InterfaceAlias
    EdgeTraversalPolicy = 'Block'
}

if ($PSCmdlet.ShouldProcess("$($hrisAddress):5002,8002,8082 on $($hrisInterface[0].InterfaceAlias)", 'Allow incoming TCP from LocalSubnet only')) {
    $hrisAdministrator = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $hrisAdministrator.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Run this script in an Administrator PowerShell window to configure Windows Firewall.'
    }

    $hrisRule = Get-NetFirewallRule -Name $hrisRuleName -ErrorAction SilentlyContinue
    if ($hrisRule) {
        if ($hrisRule.Group -ne 'HRIS LAN') {
            throw 'An unrelated firewall rule already uses the HRIS rule name.'
        }

        Set-NetFirewallRule @hrisRuleSettings -NewDisplayName 'HRIS frontend, backend and Reverb on LAN' | Out-Null
    } else {
        New-NetFirewallRule @hrisRuleSettings -DisplayName 'HRIS frontend, backend and Reverb on LAN' -Group 'HRIS LAN' | Out-Null
    }

    Write-Output "HRIS LAN access enabled for $($hrisAddress) on TCP 5002, 8002 and 8082 (LocalSubnet only)."
}

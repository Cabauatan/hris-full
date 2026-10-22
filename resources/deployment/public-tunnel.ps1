[CmdletBinding()]
param([ValidateSet('Start', 'Stop', 'Status', 'Check', 'RestartGateway')][string]$Action = 'Status')
$ErrorActionPreference = 'Stop'
$backendRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$frontendRoot = [IO.Path]::GetFullPath((Join-Path $backendRoot '../hris-frontend'))
$runtimeRoot = Join-Path $backendRoot 'storage/framework/public-tunnel'
$managerPath = Join-Path $runtimeRoot 'state.json'
$artisan = Join-Path $backendRoot 'artisan'
$gatewayScript = Join-Path $PSScriptRoot 'public-gateway.mjs'
$php = Join-Path $env:USERPROFILE '.config/herd/bin/php85/php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { $php = (Get-Command php.exe -ErrorAction Stop).Source }
$node = (Get-Command node.exe -ErrorAction Stop).Source
$cloudflared = (Get-Command cloudflared.exe -ErrorAction Stop).Source
$utf8 = New-Object System.Text.UTF8Encoding($false)
$script:manager = $null

function Write-Manager {
    [IO.File]::WriteAllText($managerPath, ($script:manager | ConvertTo-Json -Depth 8), $utf8)
}
function Read-SharedLog([string]$path) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { return '' }
    $stream = [IO.File]::Open($path, [IO.FileMode]::Open, [IO.FileAccess]::Read, ([IO.FileShare]::ReadWrite -bor [IO.FileShare]::Delete))
    $reader = New-Object IO.StreamReader($stream)
    try { return $reader.ReadToEnd() } finally { $reader.Dispose() }
}
function Read-Manager {
    if (-not (Test-Path -LiteralPath $managerPath -PathType Leaf)) { return $null }
    $saved = Get-Content -LiteralPath $managerPath -Raw | ConvertFrom-Json
    if ($saved.Version -ne 1 -or $saved.BackendRoot -ne $backendRoot -or
        -not ([IO.Path]::GetFullPath($saved.RunDirectory)).StartsWith($runtimeRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Unexpected public-tunnel state; no process was stopped.'
    }
    return $saved
}
function Quote-Argument([string]$value) {
    if ($value.Contains('"')) { throw 'Unexpected quote in a process argument.' }
    return '"' + $value + '"'
}
function Invoke-Environment([hashtable]$values, [scriptblock]$operation) {
    $previous = @{}
    try {
        foreach ($key in $values.Keys) {
            $previous[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
            [Environment]::SetEnvironmentVariable($key, [string]$values[$key], 'Process')
        }
        & $operation
    } finally {
        foreach ($key in $previous.Keys) { [Environment]::SetEnvironmentVariable($key, $previous[$key], 'Process') }
    }
}
function Start-OwnedProcess([string]$role, [string]$executable, [string[]]$arguments, [string]$directory, [hashtable]$values) {
    $logPrefix = Join-Path $script:manager.RunDirectory $role.ToLowerInvariant()
    $argumentLine = ($arguments | ForEach-Object { Quote-Argument $_ }) -join ' '
    $process = Invoke-Environment $values {
        Start-Process -FilePath $executable -ArgumentList $argumentLine -WorkingDirectory $directory -WindowStyle Hidden -PassThru -RedirectStandardOutput ($logPrefix+'.out.log') -RedirectStandardError ($logPrefix+'.err.log')
    }
    $entry = [ordered]@{ Role=$role; Pid=$process.Id; StartedUtc=$process.StartTime.ToUniversalTime().ToString('o') }
    $script:manager.Processes = @($script:manager.Processes) + $entry
    Write-Manager
    return $process
}
function Get-OwnedProcess($entry) {
    $process = Get-Process -Id ([int]$entry.Pid) -ErrorAction SilentlyContinue
    if (-not $process) { return $null }
    $started = ([datetime]$entry.StartedUtc).ToUniversalTime()
    if ([Math]::Abs(($process.StartTime.ToUniversalTime() - $started).TotalSeconds) -gt 1) { throw 'A saved process ID has been reused; it was not stopped.' }
    $command = (Get-CimInstance Win32_Process -Filter ('ProcessId='+$process.Id)).CommandLine
    $valid = switch ([string]$entry.Role) {
        'Gateway' { $command -and $command.Contains($gatewayScript) -and $command.Contains($script:manager.RunDirectory) }
        'Cloudflared' { $command -and $command.Contains($script:manager.RunDirectory) -and $command.Contains('127.0.0.1:5003') }
        'Backend' { $command -and $command.Contains('127.0.0.1:8003') -and $command.Contains($backendRoot) }
        'Queue' { $command -and $command.Contains($artisan) -and $command.Contains('queue:work') }
        'Reverb' { $command -and $command.Contains($artisan) -and $command.Contains('reverb:start') -and $command.Contains('8082') }
        'Telegram' { $command -and $command.Contains($artisan) -and $command.Contains('telegram:poll') }
        default { $false }
    }
    if (-not $valid) { throw 'Saved process identity does not match the HRIS tunnel; it was not stopped.' }
    return $process
}
function Stop-OwnedProcess($entry) {
    $process = Get-OwnedProcess $entry
    if ($process) {
        Stop-Process -Id $process.Id
        if (-not $process.WaitForExit(10000)) { throw 'A managed process did not stop; its port was not reused.' }
    }
}
function Test-Port([int]$port) {
    $client = New-Object Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1', $port); return $true } catch { return $false } finally { $client.Dispose() }
}
function Get-ProjectWorker([string]$pattern) {
    return ,@(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -and $_.CommandLine.Contains($artisan) -and ($_.CommandLine -replace '"','') -match $pattern })
}
function Stop-ProjectQueue([int[]]$expectedPids) {
    $workers = Get-ProjectWorker '\squeue:work(?:\s|"|$)'
    if (-not $workers.Count) { return }
    if (@($workers | Where-Object { $_.ProcessId -notin $expectedPids }).Count) { throw 'An additional queue worker appeared; it was not stopped.' }
    & $php $artisan queue:restart --no-interaction | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not signal the queue to finish its current jobs.' }
    $deadline = (Get-Date).AddSeconds(75)
    do {
        $remaining = @($workers | Where-Object { Get-Process -Id $_.ProcessId -ErrorAction SilentlyContinue })
        if (-not $remaining.Count) { return }
        Start-Sleep -Milliseconds 250
    } while ((Get-Date) -lt $deadline)
    throw 'Queue worker did not exit gracefully. No worker was forcibly stopped.'
}
function Stop-Telegram($workers) {
    if (@($workers).Count) {
        foreach ($worker in $workers) { Stop-Process -Id $worker.ProcessId -ErrorAction Stop }
        $script:manager.TelegramRestartAfterUtc = (Get-Date).ToUniversalTime().AddSeconds(93).ToString('o')
        Write-Manager
    }
}
function Wait-TelegramLock {
    if ($script:manager.TelegramRestartAfterUtc) {
        $until = ([datetime]$script:manager.TelegramRestartAfterUtc).ToUniversalTime()
        $remaining = [Math]::Max(0, [Math]::Ceiling(($until - (Get-Date).ToUniversalTime()).TotalSeconds))
        if ($remaining -gt 0) { Write-Host ('Waiting up to '+$remaining+'s for the previous Telegram polling lock to expire...') }
        while ((Get-Date).ToUniversalTime() -lt $until) { Start-Sleep -Milliseconds 500 }
    }
}
function Start-Worker([string]$role, [hashtable]$values, [bool]$lanMode = $false) {
    $queueOption = if ($lanMode) { '--queue=notifications' } else { '--queue=notifications,default' }
    $arguments = switch ($role) {
        'Reverb' { @($artisan, 'reverb:start', '--host=0.0.0.0', '--port=8082', '--no-interaction') }
        'Queue' { @($artisan, 'queue:work', 'database', $queueOption, '--sleep=1', '--tries=4', '--timeout=60', '--no-interaction') }
        'Telegram' { @($artisan, 'telegram:poll', '--no-interaction') }
    }
    return Start-OwnedProcess $role $php $arguments $backendRoot $values
}
function Stop-PublicTunnel {
    if (-not $script:manager) { Write-Host 'No managed public tunnel is running.'; return }
    $script:manager.Status = 'stopping'
    Write-Manager
    $gatewayState = Join-Path $script:manager.RunDirectory 'gateway.json'
    if (Test-Path -LiteralPath $gatewayState -PathType Leaf) { Remove-Item -LiteralPath $gatewayState }
    foreach ($entry in @($script:manager.Processes | Where-Object { $_.Role -in @('Cloudflared','Gateway','Backend') })) {
        Stop-OwnedProcess $entry
    }
    $publicTelegram = @($script:manager.Processes | Where-Object { $_.Role -eq 'Telegram' })
    foreach ($entry in $publicTelegram) {
        $process = Get-OwnedProcess $entry
        if ($process) {
            Stop-OwnedProcess $entry
            $script:manager.TelegramRestartAfterUtc = (Get-Date).ToUniversalTime().AddSeconds(93).ToString('o')
            Write-Manager
        }
    }
    $queuePids = @()
    foreach ($entry in @($script:manager.Processes | Where-Object { $_.Role -eq 'Queue' })) {
        $process = Get-OwnedProcess $entry
        if ($process) { $queuePids += $process.Id }
    }
    if ($queuePids.Count) { Stop-ProjectQueue $queuePids }
    foreach ($entry in @($script:manager.Processes | Where-Object { $_.Role -eq 'Reverb' })) {
        Stop-OwnedProcess $entry
    }
    $script:manager.Processes = @()
    foreach ($role in @('Reverb','Queue')) {
        if ($script:manager.Restore.$role -and -not (Get-ProjectWorker $(if($role -eq 'Reverb'){'\sreverb:start(?:\s|"|$)'}else{'\squeue:work(?:\s|"|$)'})).Count) {
            [void](Start-Worker $role @{} $true)
        }
    }
    if ($script:manager.Restore.Telegram -and -not (Get-ProjectWorker '\stelegram:poll(?:\s|"|$)').Count) {
        Wait-TelegramLock
        [void](Start-Worker 'Telegram' @{} $true)
    }
    $script:manager.Processes = @()
    $script:manager.Url = $null
    $script:manager.Status = 'stopped'
    Write-Manager
    Write-Host 'Public link stopped. Original LAN worker configuration restored.'
}

if ($Action -eq 'RestartGateway') {
    $script:manager = Read-Manager
    if (-not $script:manager -or $script:manager.Status -ne 'running') { throw 'Start the public tunnel before restarting its gateway.' }
    $gatewayEntries = @($script:manager.Processes | Where-Object { $_.Role -eq 'Gateway' })
    if ($gatewayEntries.Count -ne 1) { throw 'Expected exactly one managed gateway; no process was stopped.' }
    $script:manager.Status = 'restarting_gateway'
    Write-Manager
    try {
        Stop-OwnedProcess $gatewayEntries[0]
        $script:manager.Processes = @($script:manager.Processes | Where-Object { $_.Role -ne 'Gateway' })
        Write-Manager
        $dist = Join-Path $script:manager.RunDirectory 'dist'
        $gatewayState = Join-Path $script:manager.RunDirectory 'gateway.json'
        [void](Start-OwnedProcess 'Gateway' $node @($gatewayScript,'--dist',$dist,'--state',$gatewayState,'--port','5003','--backend-port','8003','--reverb-port','8082') $backendRoot @{})
        $deadline = (Get-Date).AddSeconds(10)
        $ready = $false
        do {
            try {
                $probe = Invoke-WebRequest -Uri 'http://127.0.0.1:5003/up' -Headers @{Host=([uri]$script:manager.Url).Host} -UseBasicParsing -TimeoutSec 3
                $ready = [int]$probe.StatusCode -eq 200
            } catch { $ready = $false }
            if (-not $ready) { Start-Sleep -Milliseconds 200 }
        } while (-not $ready -and (Get-Date) -lt $deadline)
        if (-not $ready) { throw 'The restarted gateway did not pass its backend health check.' }
        $script:manager.Status = 'running'
        Write-Manager
        Write-Host ('Gateway restarted. Public URL unchanged: '+$script:manager.Url)
    } catch {
        $script:manager.Status = 'gateway_error'
        Write-Manager
        throw
    }
    exit 0
}

if ($Action -eq 'Status') {
    $script:manager = Read-Manager
    if (-not $script:manager -or $script:manager.Status -ne 'running') { Write-Host 'Public tunnel is not running.'; exit 0 }
    foreach ($entry in $script:manager.Processes) {
        if (-not (Get-OwnedProcess $entry)) { throw ('A managed '+$entry.Role+' process stopped. Run Stop, then Start.') }
    }
    Write-Host ('HRIS: '+$script:manager.Url)
    Write-Host ('Applicants: '+$script:manager.Url+'/careers')
    exit 0
}
if ($Action -eq 'Stop') {
    $script:manager = Read-Manager
    Stop-PublicTunnel
    exit 0
}
foreach ($required in @($artisan, (Join-Path $backendRoot '.env'), $gatewayScript, (Join-Path $frontendRoot 'node_modules/vite/bin/vite.js'))) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { throw 'A required HRIS source/runtime file is missing.' }
}
if ($Action -eq 'Check') {
    Write-Host 'PASS: PHP, Node, cloudflared, HRIS backend and Vue dependencies are available.'
    exit 0
}

$script:manager = Read-Manager
if ($script:manager -and $script:manager.Status -ne 'stopped') { throw 'A previous tunnel state exists. Run -Action Status or -Action Stop first.' }
if ((Test-Port 5003) -or (Test-Port 8003)) { throw 'Loopback ports 5003/8003 are occupied; no process was stopped.' }
$settingsOutput = & $php (Join-Path $PSScriptRoot 'public-settings.php')
if ($LASTEXITCODE -ne 0) { throw 'Could not read the safe public runtime settings.' }
$settings = ($settingsOutput -join "`n") | ConvertFrom-Json
if (-not $settings.reverbKey) { throw 'Reverb must be configured before starting the public tunnel.' }
$oldReverb = Get-ProjectWorker '\sreverb:start(?:\s|"|$)'
$oldQueue = Get-ProjectWorker '\squeue:work(?:\s|"|$)'
$oldTelegram = Get-ProjectWorker '\stelegram:poll(?:\s|"|$)'
if ((Test-Port 8082) -and -not $oldReverb.Count) { throw 'Port 8082 belongs to another process; it was left running.' }
New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
$runDirectory = Join-Path $runtimeRoot ('run-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[guid]::NewGuid().ToString('N').Substring(0,8))
New-Item -ItemType Directory -Path $runDirectory | Out-Null
$dist = Join-Path $runDirectory 'dist'
New-Item -ItemType Directory -Path $dist | Out-Null
[IO.File]::WriteAllText((Join-Path $dist 'index.html'), '<!doctype html><title>Preparing HRIS</title>', $utf8)
$script:manager = [pscustomobject]@{ Version=1; BackendRoot=$backendRoot; RunDirectory=$runDirectory; Status='starting'; Url=$null; Processes=@(); Restore=[pscustomobject]@{Reverb=($oldReverb.Count -gt 0);Queue=($oldQueue.Count -gt 0);Telegram=($oldTelegram.Count -gt 0)}; TelegramRestartAfterUtc=$null }
Write-Manager
try {
    $gatewayState = Join-Path $runDirectory 'gateway.json'
    [void](Start-OwnedProcess 'Gateway' $node @($gatewayScript,'--dist',$dist,'--state',$gatewayState,'--port','5003','--backend-port','8003','--reverb-port','8082') $backendRoot @{})
    $deadline=(Get-Date).AddSeconds(10)
    while (-not (Test-Port 5003)) { if((Get-Date) -gt $deadline){throw 'Gateway did not start.'}; Start-Sleep -Milliseconds 200 }
    $emptyConfig=Join-Path $runDirectory 'quick-tunnel.yml'
    [IO.File]::WriteAllText($emptyConfig, "{}`n", $utf8)
    [void](Start-OwnedProcess 'Cloudflared' $cloudflared @('tunnel','--config',$emptyConfig,'--no-autoupdate','--protocol','http2','--url','http://127.0.0.1:5003','--metrics','127.0.0.1:0') $backendRoot @{})
    Write-Host 'Requesting a free temporary HTTPS address...'
    $deadline=(Get-Date).AddSeconds(60)
    $publicUrl=$null
    do {
        $logText=''
        foreach($log in @('cloudflared.out.log','cloudflared.err.log')){$logText += Read-SharedLog (Join-Path $runDirectory $log)}
        if($logText -match 'https://[a-z0-9-]+\.trycloudflare\.com'){$publicUrl=$matches[0];break}
        Start-Sleep -Milliseconds 300
    } while((Get-Date) -lt $deadline)
    if(-not $publicUrl){throw 'Cloudflare did not allocate an HTTPS address. See the private cloudflared logs.'}
    $publicUri=[uri]$publicUrl
    $script:manager.Url=$publicUrl
    Write-Manager
    Write-Host 'Building the public frontend with WSS on HTTPS port 443...'
    $buildEnvironment=@{VITE_API_BASE_URL='/api/v1';VITE_REVERB_APP_KEY=[string]$settings.reverbKey;VITE_REVERB_HOST=$publicUri.Host;VITE_REVERB_PORT='443';VITE_REVERB_SCHEME='https'}
    Invoke-Environment $buildEnvironment {
        Push-Location -LiteralPath $frontendRoot
        try { & $node (Join-Path $frontendRoot 'node_modules/vite/bin/vite.js') build --outDir $dist --emptyOutDir; if($LASTEXITCODE -ne 0){throw 'Public frontend build failed.'} } finally { Pop-Location }
    }
    $publicEnvironment=@{APP_ENV='production';APP_DEBUG='false';APP_URL=$publicUrl;FRONTEND_URL=$publicUrl;TRUSTED_PROXIES='127.0.0.1';SESSION_SECURE_COOKIE='true';SESSION_HTTP_ONLY='true';SESSION_ENCRYPT='true';SESSION_DOMAIN='null';SESSION_COOKIE='hris_public_session';APP_CONFIG_CACHE=(Join-Path $runDirectory 'config.php');APP_ROUTES_CACHE=(Join-Path $runDirectory 'routes.php');APP_EVENTS_CACHE=(Join-Path $runDirectory 'events.php')}
    $backendArguments=@('-d','display_errors=0','-d','log_errors=1','-d','zend.exception_ignore_args=1','-d','upload_max_filesize=30M','-d','post_max_size=32M','-S','127.0.0.1:8003',(Join-Path $backendRoot 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'))
    [void](Start-OwnedProcess 'Backend' $php $backendArguments (Join-Path $backendRoot 'public') $publicEnvironment)
    $deadline=(Get-Date).AddSeconds(10)
    while(-not (Test-Port 8003)){if((Get-Date) -gt $deadline){throw 'Private public-session backend did not start.'};Start-Sleep -Milliseconds 200}
    Stop-Telegram $oldTelegram
    Stop-ProjectQueue @($oldQueue | ForEach-Object { [int]$_.ProcessId })
    foreach($worker in $oldReverb){Stop-Process -Id $worker.ProcessId}
    $deadline=(Get-Date).AddSeconds(10)
    while(Test-Port 8082){if((Get-Date) -gt $deadline){throw 'Reverb did not release port 8082.'};Start-Sleep -Milliseconds 200}
    $origins=@($settings.reverbOrigins)+@($publicUri.Host)
    $reverbEnvironment=$publicEnvironment.Clone()
    $reverbEnvironment['REVERB_ALLOWED_ORIGINS']=(@($origins | Select-Object -Unique) -join ',')
    [void](Start-Worker 'Reverb' $reverbEnvironment)
    [void](Start-Worker 'Queue' $publicEnvironment)
    if($settings.telegramEnabled){Wait-TelegramLock;[void](Start-Worker 'Telegram' $publicEnvironment)}
    Start-Sleep -Seconds 2
    foreach($entry in $script:manager.Processes){if(-not(Get-OwnedProcess $entry)){throw ('A '+$entry.Role+' process stopped during startup.')}}
    [IO.File]::WriteAllText($gatewayState, (@{url=$publicUrl}|ConvertTo-Json -Compress), $utf8)
    $probe=Invoke-WebRequest -Uri 'http://127.0.0.1:5003/up' -Headers @{Host=$publicUri.Host} -UseBasicParsing -TimeoutSec 15
    if([int]$probe.StatusCode -ne 200){throw 'The HTTPS gateway health check failed.'}
    $script:manager.Status='running'
    Write-Manager
    Write-Host ('HRIS: '+$publicUrl)
    Write-Host ('Applicants: '+$publicUrl+'/careers')
    Write-Host 'Keep this PC and internet connected. Use stop-hris-public.bat to close public access.'
} catch {
    Write-Warning 'Public setup failed; closing the temporary link and restoring LAN workers.'
    Stop-PublicTunnel
    throw
}

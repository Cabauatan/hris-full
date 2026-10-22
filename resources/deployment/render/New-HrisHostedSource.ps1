[CmdletBinding()]
param(
    [string]$FrontendRoot = '',
    [string]$OutputDirectory = '',
    [switch]$IncludeTests
)

$ErrorActionPreference = 'Stop'

function Assert-HrisPlainPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    $currentPath = [System.IO.Path]::GetFullPath($Path)
    while ($currentPath) {
        if (Test-Path -LiteralPath $currentPath) {
            $item = Get-Item -LiteralPath $currentPath -Force
            if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
                throw 'Source and destination paths must not contain links or junctions.'
            }
        }
        $parentPath = [System.IO.Path]::GetDirectoryName($currentPath)
        if ($parentPath -eq $currentPath) { break }
        $currentPath = $parentPath
    }
}

function Test-HrisHostedSourcePath {
    param([string]$RelativePath, [string]$Kind)

    $relative = $RelativePath.Replace('\', '/')
    if ($relative -match '(^|/)(\.env[^/]*|\.git|\.svn|\.hg|\.agents|\.codex|\.aws|\.ssh|\.runtime|\.vscode|\.idea|node_modules|vendor|coverage|uploads|logs)(/|$)' -or
        $relative -match '(^|/)(auth\.json|\.npmrc|\.phpunit\.result\.cache)$' -or
        $relative -match '\.(sqlite[0-9]*|db)(-(shm|wal|journal))?$' -or
        $relative -match '\.(log|sql|dump|zip|gz|7z|rar|key|pem|p12|pfx|crt|cer|jks|bak|backup)$') {
        return $false
    }
    if ($Kind -eq 'backend' -and ($relative -match '^(bootstrap/cache|public/(build|storage|hot)|resources/frontend)(/|$)')) {
        return $false
    }
    if ($Kind -eq 'frontend' -and $relative -match '^(dist|build)(/|$)') {
        return $false
    }
    return $true
}

function Add-HrisHostedSourcePath {
    param([string]$Root, [string]$RelativePath, [string]$Kind, [System.Collections.Generic.List[object]]$Entries)

    if (-not (Test-HrisHostedSourcePath -RelativePath $RelativePath -Kind $Kind)) { return }
    $sourcePath = Join-Path $Root $RelativePath
    Assert-HrisPlainPath -Path $sourcePath
    $item = Get-Item -LiteralPath $sourcePath -Force
    if ($item.PSIsContainer) {
        foreach ($child in Get-ChildItem -LiteralPath $sourcePath -Force) {
            Add-HrisHostedSourcePath -Root $Root -RelativePath ($RelativePath + '/' + $child.Name) -Kind $Kind -Entries $Entries
        }
        return
    }
    $relative = $RelativePath.Replace('\', '/')
    if ($Kind -eq 'frontend') { $relative = 'resources/frontend/' + $relative }
    $Entries.Add([pscustomobject]@{ Source = $item.FullName; Relative = $relative; Kind = $Kind })
}

function Get-HrisHostedSecrets {
    param([string]$EnvironmentPath)

    $values = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::Ordinal)
    if (-not (Test-Path -LiteralPath $EnvironmentPath -PathType Leaf)) {
        throw 'The backend environment file is required for the private secret scan.'
    }
    Assert-HrisPlainPath -Path $EnvironmentPath
    foreach ($line in [System.IO.File]::ReadAllLines($EnvironmentPath)) {
        if ($line -notmatch '^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$') { continue }
        $environmentKey = $Matches[1]
        $value = $Matches[2].Trim()
        if ($environmentKey -notmatch '(PASSWORD|SECRET|TOKEN|PRIVATE_KEY|PROXY_KEY|APP_KEY|API_KEY|ACCESS_KEY)$') { continue }
        if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
            $value = $value.Substring(1, $value.Length - 2)
        } else {
            $value = ($value -split '\s+#', 2)[0].Trim()
        }
        if (-not $value -or $value -match '^(null|true|false|\$\{[A-Z0-9_]+\}|replace[-_].*|your[-_].*)$') { continue }
        [void]$values.Add($value)
        if ($environmentKey -eq 'APP_KEY' -and $value.StartsWith('base64:')) {
            [void]$values.Add($value.Substring(7))
        }
        if ($environmentKey -eq 'MAIL_PASSWORD' -and $value -match '\s') {
            [void]$values.Add(($value -replace '\s', ''))
        }
    }
    return ,@($values)
}

function Get-HrisHostedSecretMatchCount {
    param([string[]]$Paths, [string[]]$Secrets)

    $matchingFiles = 0
    foreach ($path in $Paths) {
        $contents = [System.IO.File]::ReadAllText($path)
        foreach ($secret in $Secrets) {
            if ($contents.IndexOf($secret, [System.StringComparison]::Ordinal) -ge 0) {
                $matchingFiles++
                break
            }
        }
    }
    return $matchingFiles
}

function New-HrisHostedSource {
    [CmdletBinding()]
    param([string]$BackendRoot, [string]$FrontendRoot, [string]$OutputDirectory, [switch]$IncludeTests)

    $BackendRoot = [System.IO.Path]::GetFullPath($BackendRoot).TrimEnd('\', '/')
    if (-not $FrontendRoot) { $FrontendRoot = Join-Path (Split-Path $BackendRoot -Parent) 'hris-frontend' }
    $FrontendRoot = [System.IO.Path]::GetFullPath($FrontendRoot).TrimEnd('\', '/')
    Assert-HrisPlainPath -Path $BackendRoot
    Assert-HrisPlainPath -Path $FrontendRoot
    if (-not (Test-Path -LiteralPath (Join-Path $BackendRoot 'artisan') -PathType Leaf) -or
        -not (Test-Path -LiteralPath (Join-Path $FrontendRoot 'src') -PathType Container)) {
        throw 'Expected the existing HRIS backend and Vue frontend source directories.'
    }

    $stagingRoot = [System.IO.Path]::GetFullPath((Join-Path $BackendRoot 'storage/framework/testing'))
    if (-not $OutputDirectory) {
        $OutputDirectory = Join-Path $stagingRoot ('hosted-source-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
    }
    $OutputDirectory = [System.IO.Path]::GetFullPath($OutputDirectory).TrimEnd('\', '/')
    if (-not [System.StringComparer]::OrdinalIgnoreCase.Equals([System.IO.Path]::GetDirectoryName($OutputDirectory), $stagingRoot) -or
        [System.IO.Path]::GetFileName($OutputDirectory) -notmatch '^hosted-source-[A-Za-z0-9][A-Za-z0-9._-]*$') {
        throw 'Output must be a new hosted-source-* directory directly inside the backend storage/framework/testing directory.'
    }
    Assert-HrisPlainPath -Path $OutputDirectory
    if (Test-Path -LiteralPath $OutputDirectory) { throw 'The destination already exists; no files were overwritten.' }

    $entries = [System.Collections.Generic.List[object]]::new()
    foreach ($relative in @('app', 'bootstrap', 'config', 'database/migrations', 'database/seeders', 'database/factories', 'public', 'resources', 'routes', 'artisan', 'composer.json', 'composer.lock')) {
        Add-HrisHostedSourcePath -Root $BackendRoot -RelativePath $relative -Kind 'backend' -Entries $entries
    }
    foreach ($relative in @('src', 'public', 'index.html', 'package.json', 'package-lock.json', 'vite.config.js', 'jsconfig.json')) {
        Add-HrisHostedSourcePath -Root $FrontendRoot -RelativePath $relative -Kind 'frontend' -Entries $entries
    }
    if ($IncludeTests) {
        foreach ($relative in @('tests', 'phpunit.xml')) { Add-HrisHostedSourcePath -Root $BackendRoot -RelativePath $relative -Kind 'backend' -Entries $entries }
        Add-HrisHostedSourcePath -Root $FrontendRoot -RelativePath 'tests' -Kind 'frontend' -Entries $entries
    }

    $secrets = Get-HrisHostedSecrets -EnvironmentPath (Join-Path $BackendRoot '.env')
    $preflightMatches = Get-HrisHostedSecretMatchCount -Paths @($entries | ForEach-Object { $_.Source }) -Secrets $secrets
    if ($preflightMatches) { throw "Private secret scan failed: $preflightMatches matching source file(s). No staging directory was created." }

    [void][System.IO.Directory]::CreateDirectory($stagingRoot)
    Assert-HrisPlainPath -Path $stagingRoot
    [void](New-Item -ItemType Directory -Path $OutputDirectory -ErrorAction Stop)
    foreach ($entry in $entries) {
        $destination = Join-Path $OutputDirectory $entry.Relative
        [void][System.IO.Directory]::CreateDirectory([System.IO.Path]::GetDirectoryName($destination))
        Assert-HrisPlainPath -Path $entry.Source
        Assert-HrisPlainPath -Path $destination
        [System.IO.File]::Copy($entry.Source, $destination, $false)
    }
    $utf8 = [System.Text.UTF8Encoding]::new($false)
    foreach ($relative in @('bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs')) {
        $directory = Join-Path $OutputDirectory $relative
        [void][System.IO.Directory]::CreateDirectory($directory)
        [System.IO.File]::WriteAllText((Join-Path $directory '.gitignore'), "*`n!.gitignore`n", $utf8)
    }
    $dockerIgnore = @'
**
!app/
!app/**
!bootstrap/
!bootstrap/**
!config/
!config/**
!database/
!database/**
!public/
!public/**
!resources/
!resources/views/
!resources/views/**
!resources/deployment/
!resources/deployment/render/
!resources/deployment/render/**
!routes/
!routes/**
!artisan
!composer.json
!composer.lock
bootstrap/cache/**
public/storage
public/build
public/hot
resources/frontend/**
**/.env
**/.env.*
**/*.pem
**/*.key
**/*.p12
**/*.pfx
**/auth.json
**/node_modules/**
**/vendor/**
**/.git/**
'@
    $gitIgnore = @'
/.env
/.env.*
**/.env
**/.env.*
**/auth.json
**/*.key
**/*.pem
**/*.p12
**/*.pfx
**/node_modules/
**/vendor/
**/dist/
**/*.log
**/*.sqlite*
**/*.db
/public/storage
/public/build/
/public/hot
/bootstrap/cache/*
!/bootstrap/cache/.gitignore
/storage/*
!/storage/app/
!/storage/framework/
!/storage/logs/
/storage/app/*
!/storage/app/private/
!/storage/app/public/
/storage/framework/*
!/storage/framework/cache/
!/storage/framework/sessions/
!/storage/framework/views/
/storage/framework/cache/*
!/storage/framework/cache/data/
'@
    [System.IO.File]::WriteAllText((Join-Path $OutputDirectory '.dockerignore'), $dockerIgnore.Replace("`r`n", "`n") + "`n", $utf8)
    [System.IO.File]::WriteAllText((Join-Path $OutputDirectory '.gitignore'), $gitIgnore.Replace("`r`n", "`n") + "`n", $utf8)

    $stagedFiles = @(Get-ChildItem -LiteralPath $OutputDirectory -File -Force -Recurse | Sort-Object FullName)
    $manifest = foreach ($file in $stagedFiles) {
        $relative = $file.FullName.Substring($OutputDirectory.Length + 1).Replace('\', '/')
        (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant() + '  ' + $relative
    }
    [System.IO.File]::WriteAllText((Join-Path $OutputDirectory 'SHA256SUMS'), ($manifest -join "`n") + "`n", $utf8)
    $finalMatches = Get-HrisHostedSecretMatchCount -Paths @($stagedFiles | ForEach-Object { $_.FullName }) -Secrets $secrets
    if ($finalMatches) { throw "Private secret scan failed: $finalMatches matching staged file(s). This directory must not be published." }

    [pscustomobject]@{
        Directory = $OutputDirectory
        BackendFiles = @($entries | Where-Object { $_.Kind -eq 'backend' }).Count
        FrontendFiles = @($entries | Where-Object { $_.Kind -eq 'frontend' }).Count
        StagedFiles = $stagedFiles.Count + 1
        SecretValuesChecked = $secrets.Count
        SecretFileMatches = $finalMatches
        IncludesTests = [bool]$IncludeTests
        ManifestSHA256 = (Get-FileHash -LiteralPath (Join-Path $OutputDirectory 'SHA256SUMS') -Algorithm SHA256).Hash.ToLowerInvariant()
    }
}

$backendRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
New-HrisHostedSource -BackendRoot $backendRoot -FrontendRoot $FrontendRoot -OutputDirectory $OutputDirectory -IncludeTests:$IncludeTests

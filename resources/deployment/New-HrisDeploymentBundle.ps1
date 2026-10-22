[CmdletBinding()]
param(
    [string]$FrontendRoot = '',
    [string]$OutputPath = ''
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$backendRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if (-not $FrontendRoot) {
    $FrontendRoot = Join-Path (Split-Path $backendRoot -Parent) 'hris-frontend'
}
$FrontendRoot = (Resolve-Path -LiteralPath $FrontendRoot).Path
if (-not (Test-Path -LiteralPath (Join-Path $backendRoot 'artisan') -PathType Leaf) -or
    -not (Test-Path -LiteralPath (Join-Path $FrontendRoot 'src') -PathType Container)) {
    throw 'Expected the HRIS backend and Vue source directories.'
}
if (-not $OutputPath) {
    $OutputPath = Join-Path $backendRoot ('storage/framework/testing/hris-deployment-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
if (-not (Test-Path -LiteralPath (Split-Path $OutputPath -Parent) -PathType Container)) {
    throw 'The output parent directory must already exist.'
}

$entries = [System.Collections.Generic.List[object]]::new()
function Add-SourcePath([string]$sourceRoot, [string]$relativePath, [string]$prefix) {
    $path = Join-Path $sourceRoot $relativePath
    $item = Get-Item -LiteralPath $path -Force
    $relative = $relativePath.Replace('\', '/')
    if ($relative -match '(^|/)(\.env[^/]*|node_modules|vendor|\.git)(/|$)' -or
        $relative -match '\.(log|sql|sqlite|zip|gz|key|pem|p12)$' -or
        ($prefix -eq 'backend' -and $relative -match '^(bootstrap/cache|public/(build|storage|hot))(/|$)')) {
        return
    }
    if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
        throw "Source links are not permitted: $relativePath"
    }
    if ($item.PSIsContainer) {
        foreach ($child in Get-ChildItem -LiteralPath $path -Force) {
            Add-SourcePath $sourceRoot ($relativePath + '/' + $child.Name) $prefix
        }
    } else {
        $entries.Add([pscustomobject]@{ Path = $item.FullName; Name = "$prefix/$relative" })
    }
}

foreach ($relative in @('app', 'bootstrap', 'config', 'database/migrations', 'database/seeders', 'database/factories', 'public', 'resources', 'routes', 'artisan', 'composer.json', 'composer.lock')) {
    Add-SourcePath $backendRoot $relative 'backend'
}
foreach ($relative in @('src', 'public', 'index.html', 'package.json', 'package-lock.json', 'vite.config.js', 'jsconfig.json')) {
    Add-SourcePath $FrontendRoot $relative 'frontend'
}

$stream = [System.IO.File]::Open($OutputPath, [System.IO.FileMode]::CreateNew)
$zip = [System.IO.Compression.ZipArchive]::new($stream, [System.IO.Compression.ZipArchiveMode]::Create)
$hashes = [System.Collections.Generic.List[string]]::new()
$complete = $false
try {
    foreach ($entry in $entries | Sort-Object Name) {
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $entry.Path, $entry.Name, [System.IO.Compression.CompressionLevel]::Optimal)
        $hashes.Add((Get-FileHash -LiteralPath $entry.Path -Algorithm SHA256).Hash.ToLowerInvariant() + '  ' + $entry.Name)
    }
    foreach ($relative in @('bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs')) {
        [void]$zip.CreateEntry("backend/$relative/")
    }
    $manifest = $zip.CreateEntry('SHA256SUMS')
    $writer = [System.IO.StreamWriter]::new($manifest.Open(), [System.Text.UTF8Encoding]::new($false))
    $writer.NewLine = "`n"
    try { $writer.WriteLine(($hashes -join "`n")) } finally { $writer.Dispose() }
    $complete = $true
} finally {
    $zip.Dispose()
    $stream.Dispose()
    if (-not $complete) {
        Remove-Item -LiteralPath $OutputPath
    }
}
[pscustomobject]@{
    Bundle = $OutputPath
    SourceFiles = $entries.Count
    Bytes = (Get-Item -LiteralPath $OutputPath).Length
    SHA256 = (Get-FileHash -LiteralPath $OutputPath -Algorithm SHA256).Hash.ToLowerInvariant()
    Includes = 'Backend/Vue sources, lockfiles, blank production templates and deployment tools'
    Excludes = 'Runtime .env, database, uploads, logs, dependencies, local builds and Git history'
}

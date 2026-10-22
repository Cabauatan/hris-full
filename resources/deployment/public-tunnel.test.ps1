[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

$backendRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$artisan = Join-Path $backendRoot 'artisan'
$testParent = [IO.Path]::GetFullPath((Join-Path $backendRoot 'storage/framework/testing'))
$fixtureRoot = Join-Path $testParent ('public-tunnel-ps-' + [guid]::NewGuid().ToString('N'))
$runtimeRoot = Join-Path $fixtureRoot 'runtime'
$managerPath = Join-Path $runtimeRoot 'state.json'
$utf8 = New-Object System.Text.UTF8Encoding($false)
$script:workerFixtures = @()
$script:passed = 0
$script:failures = @()
$script:assertions = 0

$tokens = $null
$parseErrors = $null
$source = [Management.Automation.Language.Parser]::ParseFile(
    (Join-Path $PSScriptRoot 'public-tunnel.ps1'), [ref]$tokens, [ref]$parseErrors
)
if ($parseErrors.Count) { throw 'The public tunnel launcher does not parse.' }

$functionNames = @('Get-ProjectWorker', 'Quote-Argument', 'Invoke-Environment', 'Read-SharedLog', 'Read-Manager')
foreach ($functionName in $functionNames) {
    $functionNode = $source.Find({
        param($candidate)
        $candidate -is [Management.Automation.Language.FunctionDefinitionAst] -and $candidate.Name -eq $functionName
    }, $false)
    if (-not $functionNode) { throw ('Required launcher function is missing: ' + $functionName) }
    . ([scriptblock]::Create($functionNode.Extent.Text))
}

function Get-CimInstance([string]$ClassName, [string]$Filter) {
    if ($ClassName -ne 'Win32_Process' -or $Filter -ne "Name='php.exe'") {
        throw 'The worker lookup made an unexpected process query.'
    }
    return $script:workerFixtures
}

function Assert-True([bool]$condition, [string]$message) {
    $script:assertions++
    if (-not $condition) { throw $message }
}

function Assert-Equal($expected, $actual, [string]$message) {
    $script:assertions++
    if ($expected -cne $actual) { throw $message }
}

function Assert-Throws([scriptblock]$operation, [string]$expectedMessage) {
    $caught = $null
    try { & $operation | Out-Null } catch { $caught = $_.Exception.Message }
    Assert-Equal $expectedMessage $caught 'The operation did not reject the input with the expected error.'
}

function Invoke-Test([string]$name, [scriptblock]$operation) {
    try {
        & $operation
        $script:passed++
        Write-Host ('PASS: ' + $name)
    } catch {
        $script:failures += ($name + ': ' + $_.Exception.Message)
        Write-Host ('FAIL: ' + $name + ': ' + $_.Exception.Message)
    }
}

function Write-FixtureManager([string]$runDirectory, [string]$root = $backendRoot, [int]$version = 1) {
    $state = @{ Version = $version; BackendRoot = $root; RunDirectory = $runDirectory }
    [IO.File]::WriteAllText($managerPath, ($state | ConvertTo-Json -Compress), $utf8)
}

New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
try {
    $unrelated = @(
        [pscustomobject]@{ ProcessId = 101; CommandLine = 'php.exe C:\unrelated\artisan reverb:start' },
        [pscustomobject]@{ ProcessId = 102; CommandLine = ('php.exe "' + $artisan + '" queue:work') },
        [pscustomobject]@{ ProcessId = 103; CommandLine = $null },
        [pscustomobject]@{ ProcessId = 104; CommandLine = ('php.exe "' + $artisan + '" --description=reverb:start') }
    )
    $matching = @(
        [pscustomobject]@{ ProcessId = 201; CommandLine = ('"C:\PHP\php.exe" "' + $artisan + '" "reverb:start" "--port=8082"') },
        [pscustomobject]@{ ProcessId = 202; CommandLine = ('php.exe "' + $artisan + '" reverb:start --port=8082') }
    )

    foreach ($count in @(0, 1, 2)) {
        $script:workerFixtures = $unrelated
        if ($count -gt 0) { $script:workerFixtures += $matching[0..($count - 1)] }
        Invoke-Test ('Worker lookup preserves an array with ' + $count + ' matches') {
            $workers = Get-ProjectWorker '\sreverb:start(?:\s|"|$)'
            Assert-True ($workers -is [array]) 'Worker lookup returned a scalar instead of an array.'
            Assert-Equal $count $workers.Count 'Worker count is incorrect.'
            for ($index = 0; $index -lt $count; $index++) {
                Assert-Equal (201 + $index) $workers[$index].ProcessId 'Worker lookup included the wrong process.'
            }
        }
    }

    Invoke-Test 'Process arguments containing spaces stay quoted' {
        Assert-Equal '"C:\Project With Spaces\artisan"' (Quote-Argument 'C:\Project With Spaces\artisan') 'Argument quoting changed a path containing spaces.'
    }
    Invoke-Test 'Embedded argument quotes are rejected' {
        Assert-Throws { Quote-Argument 'unsafe"argument' } 'Unexpected quote in a process argument.'
    }

    $variableSuffix = [guid]::NewGuid().ToString('N')
    $existingName = 'HRIS_TUNNEL_TEST_EXISTING_' + $variableSuffix
    $missingName = 'HRIS_TUNNEL_TEST_MISSING_' + $variableSuffix
    [Environment]::SetEnvironmentVariable($existingName, 'original-value', 'Process')
    [Environment]::SetEnvironmentVariable($missingName, $null, 'Process')
    try {
        Invoke-Test 'Temporary environment is applied and restored after success' {
            $values = @{}
            $values[$existingName] = 'temporary-existing'
            $values[$missingName] = 'temporary-new'
            $result = Invoke-Environment $values {
                Assert-Equal 'temporary-existing' ([Environment]::GetEnvironmentVariable($existingName, 'Process')) 'Existing environment value was not overridden.'
                Assert-Equal 'temporary-new' ([Environment]::GetEnvironmentVariable($missingName, 'Process')) 'New environment value was not applied.'
                return 'operation-result'
            }
            Assert-Equal 'operation-result' $result 'The environment operation output was not preserved.'
            Assert-Equal 'original-value' ([Environment]::GetEnvironmentVariable($existingName, 'Process')) 'Original environment value was not restored.'
            Assert-Equal $null ([Environment]::GetEnvironmentVariable($missingName, 'Process')) 'Temporary environment value was left behind.'
        }
        Invoke-Test 'Temporary environment is restored when the operation throws' {
            $values = @{}
            $values[$existingName] = 'temporary-existing'
            $values[$missingName] = 'temporary-new'
            Assert-Throws { Invoke-Environment $values { throw 'Expected fixture failure.' } } 'Expected fixture failure.'
            Assert-Equal 'original-value' ([Environment]::GetEnvironmentVariable($existingName, 'Process')) 'Failure did not restore the original environment value.'
            Assert-Equal $null ([Environment]::GetEnvironmentVariable($missingName, 'Process')) 'Failure left a temporary environment value behind.'
        }
    } finally {
        [Environment]::SetEnvironmentVariable($existingName, $null, 'Process')
        [Environment]::SetEnvironmentVariable($missingName, $null, 'Process')
    }

    Invoke-Test 'Active redirected output can be read while a writer holds the file' {
        $logPath = Join-Path $fixtureRoot 'shared.log'
        $writer = [IO.File]::Open($logPath, [IO.FileMode]::Create, [IO.FileAccess]::Write, [IO.FileShare]::ReadWrite)
        try {
            $bytes = $utf8.GetBytes('https://fixture-only.trycloudflare.com')
            $writer.Write($bytes, 0, $bytes.Length)
            $writer.Flush()
            Assert-Equal 'https://fixture-only.trycloudflare.com' (Read-SharedLog $logPath) 'The active log could not be read.'
        } finally { $writer.Dispose() }
    }
    Invoke-Test 'A missing redirected log returns empty text' {
        Assert-Equal '' (Read-SharedLog (Join-Path $fixtureRoot 'missing.log')) 'A missing log should not prevent startup polling.'
    }

    Invoke-Test 'Owned manager state inside the fixture runtime is accepted' {
        $ownedRun = Join-Path $runtimeRoot 'run-fixture'
        Write-FixtureManager $ownedRun
        Assert-Equal $ownedRun (Read-Manager).RunDirectory 'Owned manager state was not accepted.'
    }
    Invoke-Test 'Manager state outside the runtime is rejected' {
        Write-FixtureManager (Join-Path $fixtureRoot 'outside-runtime')
        Assert-Throws { Read-Manager } 'Unexpected public-tunnel state; no process was stopped.'
    }
    Invoke-Test 'A similar runtime directory prefix is rejected' {
        Write-FixtureManager (Join-Path ($runtimeRoot + '-other') 'run-fixture')
        Assert-Throws { Read-Manager } 'Unexpected public-tunnel state; no process was stopped.'
    }
    Invoke-Test 'Manager state for another backend is rejected' {
        Write-FixtureManager (Join-Path $runtimeRoot 'run-fixture') (Join-Path $fixtureRoot 'another-backend')
        Assert-Throws { Read-Manager } 'Unexpected public-tunnel state; no process was stopped.'
    }
    Invoke-Test 'Unknown manager state versions are rejected' {
        Write-FixtureManager (Join-Path $runtimeRoot 'run-fixture') $backendRoot 99
        Assert-Throws { Read-Manager } 'Unexpected public-tunnel state; no process was stopped.'
    }
} finally {
    $resolvedFixture = [IO.Path]::GetFullPath($fixtureRoot)
    if (-not $resolvedFixture.StartsWith($testParent + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Fixture cleanup target is outside the isolated testing directory.'
    }
    if (Test-Path -LiteralPath $resolvedFixture) {
        if ((Get-Item -LiteralPath $resolvedFixture).Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw 'Fixture cleanup target unexpectedly became a link.'
        }
        Remove-Item -LiteralPath $resolvedFixture -Recurse -Force
    }
}

Write-Host ($script:passed.ToString() + ' tests passed; ' + $script:failures.Count + ' failed; ' + $script:assertions + ' assertions.')
if ($script:failures.Count) { throw 'Public tunnel launcher tests failed.' }

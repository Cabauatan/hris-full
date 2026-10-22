$ErrorActionPreference = 'Stop'
$scriptPath = Join-Path $PSScriptRoot 'New-HrisHostedSource.ps1'
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($scriptPath, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count) { throw 'Hosted source script has syntax errors.' }
foreach ($definition in $ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $false)) {
    . ([scriptblock]::Create($definition.Extent.Text))
}

$workspace = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
$fixtureRoot = Join-Path $workspace ('storage/framework/testing/hst-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
[void][System.IO.Directory]::CreateDirectory($fixtureRoot)
$utf8 = [System.Text.UTF8Encoding]::new($false)
$script:assertions = 0
$script:cases = 0
$script:fixtures = 0

function Assert-Hosted {
    param([bool]$Condition, [string]$Message)
    $script:assertions++
    if (-not $Condition) { throw $Message }
}

function New-HostedFixture {
    $script:fixtures++
    $caseRoot = Join-Path $fixtureRoot ('c' + $script:fixtures)
    $backend = Join-Path $caseRoot 'b'
    $frontend = Join-Path $caseRoot 'f'
    foreach ($relative in @('app', 'bootstrap/cache', 'config', 'database/migrations', 'database/seeders', 'database/factories', 'public', 'resources/views', 'routes', 'tests', 'storage/framework/testing')) {
        [void][System.IO.Directory]::CreateDirectory((Join-Path $backend $relative))
    }
    foreach ($relative in @('src', 'public', 'tests')) { [void][System.IO.Directory]::CreateDirectory((Join-Path $frontend $relative)) }
    foreach ($relative in @('artisan', 'composer.json', 'composer.lock', 'app/Example.php', 'bootstrap/app.php', 'config/app.php', 'public/index.php', 'resources/views/welcome.blade.php', 'routes/api.php', 'tests/ExampleTest.php', 'phpunit.xml')) {
        [System.IO.File]::WriteAllText((Join-Path $backend $relative), '{}', $utf8)
    }
    foreach ($relative in @('index.html', 'package.json', 'package-lock.json', 'vite.config.js', 'jsconfig.json', 'src/main.js', 'public/icon.svg', 'tests/example.test.mjs')) {
        [System.IO.File]::WriteAllText((Join-Path $frontend $relative), '{}', $utf8)
    }
    [System.IO.File]::WriteAllText((Join-Path $backend '.env'), "APP_KEY=base64:SyntheticPrivateKeyForHostedFixture001`nDB_PASSWORD=null`nMAIL_PASSWORD=`"synthetic smtp private value`"`n", $utf8)
    [System.IO.File]::WriteAllText((Join-Path $frontend '.env'), 'VITE_PRIVATE_FIXTURE=must-not-copy', $utf8)
    return @{ BackendRoot = $backend; FrontendRoot = $frontend }
}

function Assert-HostedRejected {
    param([scriptblock]$Action, [string]$ExpectedMessage)
    $message = ''
    try { & $Action | Out-Null } catch { $message = $_.Exception.Message }
    Assert-Hosted ($message -like $ExpectedMessage) 'Expected staging operation to be rejected safely.'
    return $message
}

$fixture = New-HostedFixture
foreach ($relative in @('bootstrap/cache/config.php', 'public/hot', 'public/storage/hidden.txt', 'public/uploads/private.txt', 'resources/.env.local', 'resources/secret.pem', 'resources/cache.zip', 'resources/database.sqlite-wal', 'resources/nested/node_modules/secret.js', 'resources/frontend/stale.js')) {
    $path = Join-Path $fixture.BackendRoot $relative
    [void][System.IO.Directory]::CreateDirectory([System.IO.Path]::GetDirectoryName($path))
    [System.IO.File]::WriteAllText($path, 'must-not-copy', $utf8)
}
$result = New-HrisHostedSource @fixture
Assert-Hosted ($result.SecretFileMatches -eq 0) 'Expected a clean source export.'
Assert-Hosted ($result.SecretValuesChecked -eq 4) 'Expected private key and SMTP password variants to be checked.'
Assert-Hosted (Test-Path -LiteralPath (Join-Path $result.Directory 'resources/frontend/src/main.js')) 'Vue source is missing from the monorepo path.'
Assert-Hosted (-not (Test-Path -LiteralPath (Join-Path $result.Directory '.env'))) 'Backend runtime environment was exported.'
Assert-Hosted (-not (Test-Path -LiteralPath (Join-Path $result.Directory 'resources/frontend/.env'))) 'Frontend runtime environment was exported.'
Assert-Hosted (-not (Test-Path -LiteralPath (Join-Path $result.Directory 'tests'))) 'Tests should be optional.'
foreach ($relative in @('bootstrap/cache/config.php', 'public/hot', 'public/storage/hidden.txt', 'public/uploads/private.txt', 'resources/.env.local', 'resources/secret.pem', 'resources/cache.zip', 'resources/database.sqlite-wal', 'resources/nested/node_modules/secret.js', 'resources/frontend/stale.js')) {
    Assert-Hosted (-not (Test-Path -LiteralPath (Join-Path $result.Directory $relative))) 'A forbidden source path was exported.'
}
foreach ($line in [System.IO.File]::ReadAllLines((Join-Path $result.Directory 'SHA256SUMS'))) {
    Assert-Hosted ($line -match '^([a-f0-9]{64})  (.+)$') 'Malformed manifest line.'
    $expectedHash = $Matches[1]
    $relative = $Matches[2]
    Assert-Hosted ((Get-FileHash -LiteralPath (Join-Path $result.Directory $relative) -Algorithm SHA256).Hash.ToLowerInvariant() -eq $expectedHash) 'Manifest hash mismatch.'
}
Assert-Hosted ([System.IO.File]::ReadAllText((Join-Path $result.Directory '.dockerignore')).StartsWith("**`n")) 'Docker context must deny files by default.'
$script:cases++

$fixture = New-HostedFixture
$result = New-HrisHostedSource @fixture -IncludeTests
Assert-Hosted (Test-Path -LiteralPath (Join-Path $result.Directory 'tests/ExampleTest.php')) 'Backend CI tests missing.'
Assert-Hosted (Test-Path -LiteralPath (Join-Path $result.Directory 'resources/frontend/tests/example.test.mjs')) 'Frontend CI tests missing.'
Assert-Hosted (Test-Path -LiteralPath (Join-Path $result.Directory 'phpunit.xml')) 'PHPUnit configuration missing.'
$script:cases++

$fixture = New-HostedFixture
$outside = Join-Path $fixtureRoot 'hosted-source-outside'
[void](Assert-HostedRejected { New-HrisHostedSource @fixture -OutputDirectory $outside } '*directly inside*')
Assert-Hosted (-not (Test-Path -LiteralPath $outside)) 'Invalid destination was created.'
$traversal = Join-Path $fixture.BackendRoot 'storage/framework/testing/../hosted-source-traversal'
[void](Assert-HostedRejected { New-HrisHostedSource @fixture -OutputDirectory $traversal } '*directly inside*')
$script:cases++

$fixture = New-HostedFixture
$existing = Join-Path $fixture.BackendRoot 'storage/framework/testing/hosted-source-existing'
[void][System.IO.Directory]::CreateDirectory($existing)
$sentinel = Join-Path $existing 'sentinel.txt'
[System.IO.File]::WriteAllText($sentinel, 'preserve-existing', $utf8)
[void](Assert-HostedRejected { New-HrisHostedSource @fixture -OutputDirectory $existing } '*already exists*')
Assert-Hosted ([System.IO.File]::ReadAllText($sentinel) -eq 'preserve-existing') 'Existing destination was modified.'
Assert-Hosted (@(Get-ChildItem -LiteralPath $existing -Force).Count -eq 1) 'Existing destination received extra files.'
$script:cases++

$fixture = New-HostedFixture
$privateValue = 'SyntheticPrivateKeyForHostedFixture001'
[System.IO.File]::WriteAllText((Join-Path $fixture.BackendRoot 'app/Leak.php'), $privateValue, $utf8)
$message = Assert-HostedRejected { New-HrisHostedSource @fixture } '*secret scan failed: 1 matching source file(s)*'
Assert-Hosted (-not $message.Contains($privateValue)) 'Secret appeared in diagnostic output.'
Assert-Hosted (@(Get-ChildItem -LiteralPath (Join-Path $fixture.BackendRoot 'storage/framework/testing') -Force).Count -eq 0) 'Secret-containing staging output was created.'
$script:cases++

$fixture = New-HostedFixture
[System.IO.File]::WriteAllText((Join-Path $fixture.FrontendRoot 'public/leak.svg'), 'syntheticsmtpprivatevalue', $utf8)
[void](Assert-HostedRejected { New-HrisHostedSource @fixture } '*secret scan failed: 1 matching source file(s)*')
$script:cases++

$fixture = New-HostedFixture
$proxySecret = 'f36b6fbf191d44a588c413349bdadbd92208cf5d606b4aa3b2707df3bad61a3c'
[System.IO.File]::AppendAllText((Join-Path $fixture.BackendRoot '.env'), ('HRIS_PROXY_KEY=' + $proxySecret + "`n"), $utf8)
[System.IO.File]::WriteAllText((Join-Path $fixture.FrontendRoot 'src/leaked-proxy-key.js'), $proxySecret, $utf8)
$message = Assert-HostedRejected { New-HrisHostedSource @fixture } '*secret scan failed: 1 matching source file(s)*'
Assert-Hosted (-not $message.Contains($proxySecret)) 'Proxy secret appeared in diagnostic output.'
Assert-Hosted (@(Get-ChildItem -LiteralPath (Join-Path $fixture.BackendRoot 'storage/framework/testing') -Force).Count -eq 0) 'Proxy-secret staging output was created.'
$script:cases++

$fixture = New-HostedFixture
$junction = Join-Path $fixture.BackendRoot 'app/linked'
[void](New-Item -ItemType Junction -Path $junction -Target $fixture.FrontendRoot)
[void](Assert-HostedRejected { New-HrisHostedSource @fixture } '*links or junctions*')
Assert-Hosted (@(Get-ChildItem -LiteralPath (Join-Path $fixture.BackendRoot 'storage/framework/testing') -Force).Count -eq 0) 'Linked source should fail before output creation.'
$script:cases++

$fixture = New-HostedFixture
$destinationJunction = Join-Path $fixture.BackendRoot 'storage/framework/testing/hosted-source-link'
[void](New-Item -ItemType Junction -Path $destinationJunction -Target $fixture.FrontendRoot)
[void](Assert-HostedRejected { New-HrisHostedSource @fixture -OutputDirectory $destinationJunction } '*links or junctions*')
Assert-Hosted (-not (Test-Path -LiteralPath (Join-Path $fixture.FrontendRoot 'artisan'))) 'Linked destination was modified.'
$script:cases++

[pscustomobject]@{ CasesPassed = $script:cases; AssertionsPassed = $script:assertions; FixtureDirectory = $fixtureRoot }

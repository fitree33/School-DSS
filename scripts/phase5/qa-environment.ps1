param(
    [ValidateSet('start', 'status', 'stop')]
    [string] $Action = 'status',
    [string] $Php = 'D:\School-DSS-Foundation\php-8.4.24\php.exe',
    [string] $Node = '',
    [switch] $Setup,
    [string] $PopplerBin = ''
)

$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$runtime = Join-Path $workspace '.foundation-runtime\phase5-qa'
$processFile = Join-Path $runtime 'processes.json'
$appUrl = 'http://127.0.0.1:8015/app/imports'

function Get-OwnedProcess($record) {
    $process = Get-Process -Id $record.pid -ErrorAction SilentlyContinue
    if ($null -eq $process) { return $null }
    if ($process.StartTime.ToUniversalTime().Ticks.ToString() -ne $record.started_utc_ticks -or $process.Path -ne $record.executable) {
        throw "Process identity changed for $($record.name); refusing to manage PID $($record.pid)."
    }
    return $process
}

function Test-Listening([int] $port) {
    $probe = [Net.Sockets.TcpClient]::new()
    try { return $probe.ConnectAsync('127.0.0.1', $port).Wait(500) -and $probe.Connected }
    catch [AggregateException] { return $false }
    finally { $probe.Dispose() }
}

function Write-ProcessRecords($records) {
    ConvertTo-Json -InputObject @($records) -Depth 4 | Set-Content -LiteralPath $processFile -Encoding UTF8
}

$records = @()
if (Test-Path -LiteralPath $processFile) {
    $records = @(Get-Content -LiteralPath $processFile -Raw | ConvertFrom-Json | ForEach-Object { $_ })
}

if ($Action -eq 'stop') {
    foreach ($record in $records) {
        $process = Get-OwnedProcess $record
        if ($null -ne $process) { Stop-Process -InputObject $process -ErrorAction Stop }
    }
    if (Test-Path -LiteralPath $processFile) { Write-ProcessRecords @() }
    Write-Output 'Phase 5 QA processes stopped. Disposable database, fixtures and logs are preserved.'
    exit 0
}

if ($Action -eq 'status') {
    foreach ($record in $records) {
        $process = Get-OwnedProcess $record
        [pscustomobject]@{ Name = $record.name; PID = $record.pid; Running = $null -ne $process }
    }
    [pscustomobject]@{ AppURL = $appUrl; AppListening = Test-Listening 8015; ProviderListening = Test-Listening 8016; Runtime = $runtime }
    if (Test-Path -LiteralPath (Join-Path $runtime 'database.sqlite')) {
        & $Php (Join-Path $PSScriptRoot 'qa.php') status
        if ($LASTEXITCODE -ne 0) { throw 'Phase 5 QA database status failed.' }
    }
    exit 0
}

foreach ($record in $records) {
    if ($null -ne (Get-OwnedProcess $record)) { throw 'Phase 5 QA is already running. Use status or stop before restarting.' }
}
if ((Test-Listening 8015) -or (Test-Listening 8016)) {
    throw 'Loopback port 8015 or 8016 is occupied. Existing processes have not been changed.'
}
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw 'Provide a PHP >= 8.3 executable using -Php.' }
& $Php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { throw 'Phase 5 requires PHP >= 8.3; the XAMPP PHP 8.1 default is unsupported.' }
if ($Node -eq '') { $Node = (Get-Command node -ErrorAction Stop).Source }

if ($Setup) {
    if ($PopplerBin -eq '') {
        $PopplerBin = Join-Path $workspace '.foundation-runtime\phase5-tools\poppler-26.07.0\poppler-26.07.0\Library\bin'
    }
    & $Php (Join-Path $PSScriptRoot 'qa.php') setup (Join-Path $PopplerBin 'pdfinfo.exe') (Join-Path $PopplerBin 'pdftotext.exe')
    if ($LASTEXITCODE -ne 0) { throw 'Phase 5 QA setup failed. Inspect the isolated runtime; existing data has not been reset.' }
}
foreach ($required in @('database.sqlite', 'settings.json', 'credentials.txt', 'setup-verification.json')) {
    if (-not (Test-Path -LiteralPath (Join-Path $runtime $required) -PathType Leaf)) {
        throw 'Prepare the disposable runtime with start -Setup before launching.'
    }
}
if (-not (Test-Path -LiteralPath (Join-Path $workspace 'laravel-app\public\build\manifest.json') -PathType Leaf)) {
    throw 'Run the frontend production build before starting browser QA.'
}

$definitions = @(
    @{ name = 'app'; executable = $Php; arguments = @('-d', 'upload_max_filesize=12M', '-d', 'post_max_size=13M', '-S', '127.0.0.1:8015', '-t', ('"' + (Join-Path $workspace 'laravel-app\public') + '"'), ('"' + (Join-Path $PSScriptRoot 'router.php') + '"')) },
    @{ name = 'provider'; executable = $Node; arguments = @(('"' + (Join-Path $PSScriptRoot 'provider-fixture.mjs') + '"')) },
    @{ name = 'worker'; executable = $Php; arguments = @(('"' + (Join-Path $PSScriptRoot 'qa.php') + '"'), 'worker') }
)
$started = @()
try {
    foreach ($definition in $definitions) {
        $process = Start-Process -FilePath $definition.executable -ArgumentList $definition.arguments -WorkingDirectory $workspace -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtime ($definition.name + '.stdout.log')) -RedirectStandardError (Join-Path $runtime ($definition.name + '.stderr.log'))
        $process.Refresh()
        $started += [pscustomobject]@{ name = $definition.name; pid = $process.Id; started_utc_ticks = $process.StartTime.ToUniversalTime().Ticks.ToString(); executable = [IO.Path]::GetFullPath($definition.executable) }
        Write-ProcessRecords $started
    }
    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if ((Test-Listening 8015) -and (Test-Listening 8016)) { $ready = $true; break }
        Start-Sleep -Milliseconds 250
    }
    if (-not $ready) { throw 'QA app/provider did not start; inspect their ignored runtime logs.' }
    $page = Invoke-WebRequest -UseBasicParsing -Uri $appUrl -TimeoutSec 10
    $provider = Invoke-RestMethod -Uri 'http://127.0.0.1:8016/health' -TimeoutSec 5
    if ($page.StatusCode -ne 200 -or $page.Content -notmatch 'id="root"' -or $provider.status -ne 'ready') { throw 'QA HTTP readiness check failed.' }
    foreach ($record in $started) {
        if ($null -eq (Get-OwnedProcess $record)) { throw "QA $($record.name) exited; inspect its ignored runtime log." }
    }
} catch {
    $launchError = $_
    foreach ($record in $started) {
        try {
            $process = Get-OwnedProcess $record
            if ($null -ne $process) { Stop-Process -InputObject $process }
        } catch { Write-Warning $_.Exception.Message }
    }
    # Preserve identities after a failed launch so status/stop can inspect survivors.
    throw $launchError
}
Write-Output "Phase 5 QA is ready at $appUrl"
Write-Output 'Credentials are in .foundation-runtime/phase5-qa/credentials.txt. They are never printed by this script.'
Write-Output 'Use qa-environment.ps1 status or stop to inspect or stop only these recorded processes.'

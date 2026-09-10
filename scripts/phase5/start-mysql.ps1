param(
    [string] $MysqlRoot = 'D:\School-DSS-Foundation\mysql-8.4.11\mysql-8.4.11-winx64'
)

$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$runtime = Join-Path $workspace '.foundation-runtime\phase5-mysql'
$data = Join-Path $runtime 'data'
$executable = Join-Path $MysqlRoot 'bin\mysqld.exe'
$client = Join-Path $MysqlRoot 'bin\mysql.exe'
$probe = [Net.Sockets.TcpClient]::new()
try {
    if ($probe.ConnectAsync('127.0.0.1', 33084).Wait(1000) -and $probe.Connected) {
        throw 'Port 33084 is already occupied; verify the owning isolated instance before reusing it.'
    }
} catch [AggregateException] {
    # A refused connection means the dedicated port is available.
} finally {
    $probe.Dispose()
}
New-Item -ItemType Directory -Path $data -Force | Out-Null
if (-not (Test-Path -LiteralPath (Join-Path $data 'auto.cnf'))) {
    & $executable --no-defaults --initialize-insecure "--basedir=$MysqlRoot" "--datadir=$data" "--log-error=$(Join-Path $runtime 'initialize.log')"
    if ($LASTEXITCODE -ne 0) { throw 'Isolated MySQL initialization failed.' }
}
$arguments = @('--no-defaults', ('--basedir="' + $MysqlRoot + '"'), ('--datadir="' + $data + '"'), '--bind-address=127.0.0.1', '--port=33084', '--mysqlx=0', '--skip-log-bin', '--mysql-native-password=OFF', ('--log-error="' + (Join-Path $runtime 'server.log') + '"'), ('--pid-file="' + (Join-Path $runtime 'server.pid') + '"')
)
$server = Start-Process -FilePath $executable -ArgumentList $arguments -WindowStyle Hidden -PassThru
$server.Id | Set-Content -LiteralPath (Join-Path $runtime 'process-id.txt')
$ready = $false
for ($attempt = 0; $attempt -lt 40; $attempt++) {
    # Windows PowerShell turns native stderr into a terminating error under Stop.
    $ErrorActionPreference = 'Continue'
    & $client --no-defaults --protocol=TCP --host=127.0.0.1 --port=33084 --user=root --connect-timeout=1 --execute="SELECT VERSION();" 2>$null
    $ErrorActionPreference = 'Stop'
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Milliseconds 250
}
if (-not $ready) { throw 'Isolated MySQL did not become ready; inspect its ignored server.log.' }
& $client --no-defaults --protocol=TCP --host=127.0.0.1 --port=33084 --user=root --execute="CREATE DATABASE IF NOT EXISTS school_dss_foundation_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw 'Could not create the guarded disposable test schema.' }
Write-Output "Isolated MySQL 8.4 is ready on 127.0.0.1:33084 (PID $($server.Id))."

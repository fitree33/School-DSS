param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PnpmArguments
)

$workspaceRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..\laravel-app')
$package = Get-Content -Raw (Join-Path $workspaceRoot 'package.json') | ConvertFrom-Json
$packageManager = [string] $package.packageManager

if ($packageManager -notmatch '^pnpm@\d+\.\d+\.\d+$') {
    throw "package.json must pin an exact pnpm version; found: $packageManager"
}

$npmCache = if ($env:SCHOOL_DSS_NPM_CACHE) {
    $env:SCHOOL_DSS_NPM_CACHE
} else {
    'D:\School-DSS-Foundation\npm-cache'
}

$env:npm_config_cache = $npmCache

Push-Location $workspaceRoot

try {
    & npx --yes $packageManager @PnpmArguments
    $exitCode = $LASTEXITCODE
} finally {
    Pop-Location
}

exit $exitCode

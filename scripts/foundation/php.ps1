param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PhpArguments
)

$phpExecutable = if ($env:SCHOOL_DSS_PHP_RUNTIME) {
    $env:SCHOOL_DSS_PHP_RUNTIME
} else {
    'D:\School-DSS-Foundation\php-8.4.24\php.exe'
}

if (-not (Test-Path -LiteralPath $phpExecutable -PathType Leaf)) {
    throw "School-DSS portable PHP runtime was not found at: $phpExecutable"
}

& $phpExecutable @PhpArguments
exit $LASTEXITCODE

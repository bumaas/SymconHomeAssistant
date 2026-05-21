[CmdletBinding()]
param(
    [string]$Root
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($Root)) {
    $scriptPath = if ($PSCommandPath) { $PSCommandPath } else { $MyInvocation.MyCommand.Path }
    $Root = Split-Path -Parent (Split-Path -Parent $scriptPath)
}

$rootPath = (Resolve-Path -LiteralPath $Root).Path
$phpFiles = Get-ChildItem -LiteralPath $rootPath -Recurse -File -Filter *.php | Sort-Object -Property FullName

if ($phpFiles.Count -eq 0) {
    Write-Host "Keine PHP-Dateien unter $rootPath gefunden."
    exit 0
}

$failedFiles = [System.Collections.Generic.List[string]]::new()

foreach ($file in $phpFiles) {
    Write-Host "Lint: $($file.FullName)"
    & php -l $file.FullName
    if ($LASTEXITCODE -ne 0) {
        $failedFiles.Add($file.FullName)
    }
}

if ($failedFiles.Count -gt 0) {
    Write-Host ""
    Write-Host "Fehlgeschlagene Dateien:"
    foreach ($failedFile in $failedFiles) {
        Write-Host "- $failedFile"
    }
    exit 1
}

Write-Host ""
Write-Host "Check: MQTT Discovery Fixtures"
& php (Join-Path $rootPath 'tests\check-mqtt-discovery-fixtures.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host ""
Write-Host "Check: MQTT Discovery Light Runtime"
& php (Join-Path $rootPath 'tests\check-mqtt-discovery-light-runtime.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host ""
Write-Host "Check: Entity MQTT Topic Runtime"
& php (Join-Path $rootPath 'tests\check-entity-mqtt-topic-runtime.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host ""
Write-Host "Check: DateTime Domain Runtime"
& php (Join-Path $rootPath 'tests\check-datetime-domain-runtime.php')
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host ""
Write-Host "OK: $($phpFiles.Count) PHP-Dateien ohne Syntaxfehler geprueft, Discovery-Fixtures, Light-Runtime-Checks und Runtime-Regressionen erfolgreich."

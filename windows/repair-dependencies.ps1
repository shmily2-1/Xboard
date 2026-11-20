#requires -Version 5.1
$ErrorActionPreference = 'Stop'
$Root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
Set-Location -LiteralPath $Root
try {
    if (!(Test-Path -LiteralPath (Join-Path $Root 'storage\framework\down'))) {
        throw 'Put the site in maintenance using 02_Stop.bat before repairing dependencies.'
    }
    $settings = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'settings.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $ini = Join-Path $PSScriptRoot 'php-cli.ini'
    $env:PHPRC = $ini
    & $settings.PhpExe -c $ini (Join-Path $PSScriptRoot 'composer.phar') install --no-dev --prefer-dist --no-interaction --no-scripts --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix --no-progress
    if ($LASTEXITCODE -ne 0) { throw 'Locked dependency restore failed.' }
    & $settings.PhpExe -c $ini (Join-Path $Root 'artisan') package:discover --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Package discovery failed.' }
    & $settings.PhpExe -c $ini (Join-Path $Root 'artisan') config:cache
    if ($LASTEXITCODE -ne 0) { throw 'Configuration cache failed.' }
    Write-Host 'Dependencies restored from composer.lock. Start the site with 01_Start.bat.'
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}

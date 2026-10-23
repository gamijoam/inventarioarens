$ErrorActionPreference = 'Stop'
$root = Join-Path $env:TEMP ('frankenphp-config-test-' + [guid]::NewGuid().ToString('N'))
$migrationScript = Join-Path $PSScriptRoot '..\migrate-frankenphp-config.ps1'

function Assert-Contains([string]$Content, [string]$Value, [string]$Message) {
    if (-not $Content.Contains($Value)) {
        Write-Host "Expected text: [$Value]"
        Write-Host "Actual config:`n$Content"
        throw $Message
    }
}

try {
    $dataRoot = Join-Path $root 'data'
    $runtime = Join-Path $root 'versions\0.1.5\runtime\frankenphp'
    $legacy = Join-Path $dataRoot 'runtime\frankenphp\php.ini'
    $versionedLegacy = Join-Path $root 'versions\0.1.4\runtime\frankenphp\php.ini'
    $legacyDirectories = @((Split-Path -Parent $legacy), (Split-Path -Parent $versionedLegacy))
    New-Item -ItemType Directory -Path $legacyDirectories -Force | Out-Null
    $legacyRuntimeDirectory = Split-Path -Parent $legacy
    $legacyExtensionDirectory = Join-Path $legacyRuntimeDirectory 'ext'
    $legacyText = @"
extension_dir = "$legacyExtensionDirectory"
extension=php_pdo_sqlite.dll
zend_extension="$legacyExtensionDirectory\php_opcache.dll"
opcache.enable=0
memory_limit=768M
date.timezone=America/Caracas
"@
    [IO.File]::WriteAllText($legacy, $legacyText)
    [IO.File]::WriteAllText($versionedLegacy, 'memory_limit=192M')

    & $migrationScript -RuntimeDirectory $runtime -DataRoot $dataRoot -LegacyConfigPaths @($legacy, $versionedLegacy)
    $migrated = [IO.File]::ReadAllText((Join-Path $runtime 'php.ini'))
    Assert-Contains $migrated ('extension_dir = "' + (Join-Path $runtime 'ext') + '"') 'No se actualizó extension_dir.'
    Assert-Contains $migrated ('zend_extension="' + (Join-Path $runtime 'ext\php_opcache.dll') + '"') 'No se actualizó la ruta de una extensión personalizada.'
    Assert-Contains $migrated 'opcache.enable=0' 'Se perdió el override de OPcache.'
    Assert-Contains $migrated 'memory_limit=768M' 'Se perdió el límite de memoria personalizado.'
    Assert-Contains $migrated 'date.timezone=America/Caracas' 'Se perdió un ajuste personalizado de PHP.'
    Assert-Contains $migrated 'extension=sqlite3' 'No se agregó una extensión requerida que faltaba.'
    $backup = Get-ChildItem -LiteralPath (Join-Path $dataRoot 'backups\frankenphp-config') -Filter php.ini -File -Recurse | Select-Object -First 1
    if (-not $backup) { throw 'No se guardó copia del php.ini anterior.' }
    if ([IO.File]::ReadAllText($backup.FullName) -ne $legacyText) { throw 'La copia de seguridad no conserva el php.ini original.' }

    $freshRuntime = Join-Path $root 'fresh\runtime\frankenphp'
    & $migrationScript -RuntimeDirectory $freshRuntime -DataRoot (Join-Path $root 'fresh-data')
    $fresh = [IO.File]::ReadAllText((Join-Path $freshRuntime 'php.ini'))
    Assert-Contains $fresh 'opcache.enable=1' 'La configuración nueva perdió el valor predeterminado de OPcache.'
    Assert-Contains $fresh 'memory_limit=512M' 'La configuración nueva perdió el límite de memoria predeterminado.'

    Write-Host 'Pruebas de migración de php.ini FrankenPHP correctas.'
} finally {
    if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
}

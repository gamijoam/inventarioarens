param(
    [string] $AppRoot = (Join-Path $env:ProgramFiles "InventarioArens"),

    [string] $SourceRoot = (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$dataRoot = Join-Path $env:ProgramData "InventarioArens"
$repairLog = Join-Path $dataRoot "logs\repair-installed.log"
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $repairLog) | Out-Null

trap {
    "[$(Get-Date -Format s)] $($_ | Out-String)" | Set-Content -LiteralPath $repairLog -Encoding UTF8
    exit 1
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (!$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Ejecuta esta reparacion como administrador."
}

if (!(Test-Path -LiteralPath $AppRoot)) {
    throw "No se encontro la instalacion en $AppRoot."
}

if (!(Test-Path -LiteralPath $SourceRoot)) {
    throw "No se encontro la fuente de reparacion en $SourceRoot."
}

$backupRoot = Join-Path $dataRoot "backups"
New-Item -ItemType Directory -Force -Path $backupRoot | Out-Null

$envFile = Join-Path $AppRoot ".env"
if (Test-Path -LiteralPath $envFile) {
    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    Copy-Item -LiteralPath $envFile -Destination (Join-Path $backupRoot "env-before-repair-$timestamp.bak") -Force
}

& (Join-Path $AppRoot "installer\windows\stop-inventario.ps1") -AppRoot $AppRoot

foreach ($relativePath in @(
    ".env.local-sqlite.example",
    "bootstrap\app.php",
    "config\cors.php",
    "app\Modules\LocalSupport\Controllers\LocalTechnicalConsoleController.php",
    "app\Modules\LocalSupport\Services\LocalTechnicalConsoleService.php",
    "app\Modules\LocalSupport\routes.php",
    "installer\windows\cacert.pem",
    "installer\windows\install-local.ps1",
    "installer\windows\start-inventario.ps1",
    "scripts\sync-worker.cmd",
    "scripts\sync-worker.ps1",
    "scripts\sync-worker-task.ps1"
)) {
    $source = Join-Path $SourceRoot $relativePath
    $target = Join-Path $AppRoot $relativePath

    if (!(Test-Path -LiteralPath $source)) {
        throw "Falta el archivo de reparacion: $source"
    }

    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null
    Copy-Item -LiteralPath $source -Destination $target -Force
}

$frontendSource = Join-Path $SourceRoot "frontend\dist"
$frontendTarget = Join-Path $AppRoot "frontend\dist"
if (!(Test-Path -LiteralPath $frontendSource)) {
    throw "Falta el frontend compilado para la reparacion: $frontendSource"
}
New-Item -ItemType Directory -Force -Path $frontendTarget | Out-Null
Copy-Item -Path (Join-Path $frontendSource '*') -Destination $frontendTarget -Recurse -Force

& (Join-Path $AppRoot "installer\windows\install-local.ps1") -AppRoot $AppRoot
if ($LASTEXITCODE -ne 0) {
    throw "La preparacion SQLite fallo con codigo $LASTEXITCODE."
}

Write-Host "Reparacion terminada. Abre Inventario Arens nuevamente." -ForegroundColor Green

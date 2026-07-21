# install-task.ps1 - Registra InventoryArensSync en Task Scheduler.
#
# Stub Fase 1: solo envuelve el legacy scripts/sync-worker-task.ps1.
# En Fase 2, este script sera reemplazado por la implementacion
# cross-platform (probablemente via schtasks.exe).
#
param(
    [string] $TenantSlug = "mi-empresa"
)

# Uso: powershell -ExecutionPolicy Bypass -File windows/install-task.ps1 -TenantSlug mi-empresa

$ErrorActionPreference = 'Stop'

# Buscar el script legacy (en scripts/ del repo Laravel).
$repoRoot = Split-Path -Parent $PSScriptRoot
$legacyScript = Join-Path $repoRoot "scripts/sync-worker-task.ps1"

if (-not (Test-Path $legacyScript)) {
    Write-Host "[x] No encontre el script legacy: $legacyScript" -ForegroundColor Red
    Write-Host "    Esperaba un script que registre la Task Scheduler." -ForegroundColor Red
    exit 1
}

Write-Host "[i] Usando legacy: $legacyScript" -ForegroundColor Cyan

# Invocar el legacy con accion posicional.
& powershell.exe -ExecutionPolicy Bypass -File $legacyScript install -TenantSlug $TenantSlug
exit $LASTEXITCODE

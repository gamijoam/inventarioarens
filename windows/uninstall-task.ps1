# uninstall-task.ps1 - Elimina InventoryArensSync de Task Scheduler.
#
# Stub Fase 1: envuelve el legacy scripts/sync-worker-task.ps1.
#
param(
    [string] $TenantSlug = "mi-empresa"
)

# Uso: powershell -ExecutionPolicy Bypass -File windows/uninstall-task.ps1 -TenantSlug mi-empresa

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$legacyScript = Join-Path $repoRoot "scripts/sync-worker-task.ps1"

if (-not (Test-Path $legacyScript)) {
    Write-Host "[x] No encontre el script legacy: $legacyScript" -ForegroundColor Red
    exit 1
}

Write-Host "[i] Usando legacy: $legacyScript" -ForegroundColor Cyan

& powershell.exe -ExecutionPolicy Bypass -File $legacyScript uninstall -TenantSlug $TenantSlug
exit $LASTEXITCODE

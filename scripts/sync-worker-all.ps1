param(
    [ValidateSet("install", "uninstall", "start", "stop", "status")]
    [string] $Action = "status"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$ConfigPath = Join-Path $RepoRoot "storage\app\sync-worker\sync-config.json"
$TaskScript = Join-Path $PSScriptRoot "sync-worker-task.ps1"

if (!(Test-Path -LiteralPath $ConfigPath)) {
    throw "No se encontro storage\app\sync-worker\sync-config.json. Ejecuta local:configure-sync-tenants primero."
}

$config = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
if (!$config.tenants) {
    throw "La configuracion no contiene empresas."
}

foreach ($property in $config.tenants.PSObject.Properties) {
    $slug = [string] $property.Name
    Write-Host "==> $Action sync para $slug" -ForegroundColor Cyan
    & (Join-Path $env:SystemRoot "System32\WindowsPowerShell\v1.0\powershell.exe") -NoProfile -ExecutionPolicy Bypass -File $TaskScript -Action $Action -TenantSlug $slug
    if ($LASTEXITCODE -ne 0) {
        throw "La tarea de $slug fallo con codigo $LASTEXITCODE."
    }
}

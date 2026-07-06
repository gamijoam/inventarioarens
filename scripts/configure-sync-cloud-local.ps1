param(
    [string] $CloudUrl = "http://217.216.80.158:8010/api",
    [Parameter(Mandatory = $true)]
    [string] $Token,
    [string] $TenantSlug = "demo-valencia",
    [string] $NodeCode = "",
    [string] $NodeName = "Local",
    [string] $InstallationCode = "",
    [string] $PhpPath = "C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe",
    [switch] $RunOnce
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$EnvPath = Join-Path $RepoRoot ".env"

if (!(Test-Path -LiteralPath $EnvPath)) {
    throw "No se encontro .env en $RepoRoot."
}

if (!(Test-Path -LiteralPath $PhpPath)) {
    throw "No se encontro PHP en $PhpPath."
}

function Set-EnvValue([string] $Name, [string] $Value) {
    $lines = Get-Content -LiteralPath $EnvPath
    $escaped = [regex]::Escape($Name)
    $replacement = "$Name=$Value"
    $found = $false

    $updated = $lines | ForEach-Object {
        if ($_ -match "^\s*$escaped\s*=") {
            $found = $true
            $replacement
        } else {
            $_
        }
    }

    if (!$found) {
        $updated += $replacement
    }

    Set-Content -LiteralPath $EnvPath -Value $updated -Encoding UTF8
}

if ([string]::IsNullOrWhiteSpace($NodeCode)) {
    $NodeCode = "LOCAL-" + ($env:COMPUTERNAME.ToUpperInvariant() -replace '[^A-Z0-9]', '-')
}

if ([string]::IsNullOrWhiteSpace($InstallationCode)) {
    $InstallationCode = $NodeCode
}

Write-Host "==> Guardando configuracion de nube en .env" -ForegroundColor Cyan
Set-EnvValue "SYNC_CLOUD_URL" $CloudUrl.TrimEnd("/")
Set-EnvValue "SYNC_CLOUD_TOKEN" $Token

Write-Host "==> Limpiando cache de Laravel local" -ForegroundColor Cyan
Push-Location $RepoRoot
try {
    & $PhpPath artisan config:clear | Out-Host

    if ($RunOnce) {
        Write-Host "==> Ejecutando prueba de sincronizacion" -ForegroundColor Cyan
        & (Join-Path $RepoRoot "scripts\sync-worker.cmd") run `
            -TenantSlug $TenantSlug `
            -NodeCode $NodeCode `
            -NodeName $NodeName `
            -InstallationCode $InstallationCode
    }
} finally {
    Pop-Location
}

Write-Host "Configuracion local lista." -ForegroundColor Green
Write-Host "URL nube: $CloudUrl"
Write-Host "Empresa: $TenantSlug"
Write-Host "Nodo: $NodeCode"

param(
    [Parameter(Mandatory = $true)]
    [string] $AppRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$php = Join-Path $AppRoot "runtime\php\php.exe"
$envFile = Join-Path $AppRoot ".env"
$envTemplate = Join-Path $AppRoot ".env.local-sqlite.example"
$dataRoot = Join-Path $env:ProgramData "InventarioArens"
$databasePath = Join-Path $dataRoot "inventario.sqlite"

if (!(Test-Path -LiteralPath $php)) {
    throw "No se encontro PHP portable en $php."
}

New-Item -ItemType Directory -Force -Path $dataRoot | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $dataRoot "logs") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $dataRoot "backups") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $AppRoot "storage\app\sync-worker") | Out-Null

if (!(Test-Path -LiteralPath $envFile)) {
    Copy-Item -LiteralPath $envTemplate -Destination $envFile
}

$envText = Get-Content -LiteralPath $envFile -Raw
$replacements = @{
    'DB_CONNECTION=.*' = 'DB_CONNECTION=sqlite'
    'DB_DATABASE=.*' = "DB_DATABASE=$databasePath"
    'APP_URL=.*' = 'APP_URL=http://127.0.0.1:8787'
}
foreach ($pattern in $replacements.Keys) {
    $replacement = $replacements[$pattern]
    if ($envText -match "(?m)^$pattern$") {
        $envText = [regex]::Replace($envText, "(?m)^$pattern$", $replacement)
    } else {
        $envText += "`r`n$replacement"
    }
}
Set-Content -LiteralPath $envFile -Value $envText -Encoding UTF8

Push-Location $AppRoot
try {
    & $php artisan key:generate --force
    if ($LASTEXITCODE -ne 0) { throw "No se pudo generar APP_KEY." }

    & $php artisan local:install-sqlite --database=$databasePath
    if ($LASTEXITCODE -ne 0) { throw "No se pudo preparar SQLite." }
} finally {
    Pop-Location
}

Write-Host "Inventario Arens instalado en $AppRoot" -ForegroundColor Green
Write-Host "Base local: $databasePath"
Write-Host "Siguiente paso: ejecutar start-inventario.ps1 y vincular una empresa."

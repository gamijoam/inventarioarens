param(
    [string] $PhpRuntime = "build\windows-runtime\php",
    [string] $OutputDirectory = "build\windows-installer"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$RuntimeSource = Join-Path $RepoRoot $PhpRuntime
$OutputRoot = Join-Path $RepoRoot $OutputDirectory
$Stage = Join-Path $OutputRoot "stage"
$PhpExe = Join-Path $RuntimeSource "php.exe"

if (!(Test-Path -LiteralPath $PhpExe)) {
    throw "PHP portable no encontrado en $PhpExe. Descarga PHP 8.3/8.4 NTS x64 y extraelo alli."
}

Push-Location $RepoRoot
try {
    $pnpm = Get-Command pnpm.cmd -ErrorAction SilentlyContinue
    if ($null -eq $pnpm) {
        throw "pnpm no esta instalado. Instala pnpm para compilar frontend/dist antes de crear el instalador."
    }
    $env:VITE_API_BASE_URL = "http://127.0.0.1:8787/api"
    & $pnpm.Source --dir frontend run build
    if ($LASTEXITCODE -ne 0) { throw "La compilacion del frontend fallo con codigo $LASTEXITCODE." }

    if (Test-Path -LiteralPath $OutputRoot) {
        Remove-Item -LiteralPath $OutputRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Force -Path $Stage | Out-Null

    $excludeDirectories = @(".git", ".harness", ".codex", "node_modules", "frontend\node_modules", "storage\logs", "storage\framework\testing")
    $excludeFiles = @(".env", "database\database.sqlite", "storage\app\sync-worker\sync-config.json")
    $robocopyArgs = @($RepoRoot, $Stage, "/E", "/NFL", "/NDL", "/NJH", "/NJS", "/NP")
    foreach ($directory in $excludeDirectories) { $robocopyArgs += "/XD"; $robocopyArgs += (Join-Path $RepoRoot $directory) }
    foreach ($file in $excludeFiles) { $robocopyArgs += "/XF"; $robocopyArgs += (Join-Path $RepoRoot $file) }
    & robocopy.exe @robocopyArgs
    if ($LASTEXITCODE -gt 7) { throw "Robocopy fallo con codigo $LASTEXITCODE." }

    New-Item -ItemType Directory -Force -Path (Join-Path $Stage "runtime\php") | Out-Null
    Copy-Item -Path (Join-Path $RuntimeSource "*") -Destination (Join-Path $Stage "runtime\php") -Recurse -Force

    New-Item -ItemType Directory -Force -Path (Join-Path $Stage "installer\windows") | Out-Null
    Copy-Item -Path (Join-Path $RepoRoot "installer\windows\*") -Destination (Join-Path $Stage "installer\windows") -Recurse -Force
    Copy-Item -Path (Join-Path $RepoRoot "installer\windows\InventarioArens.iss") -Destination $OutputRoot -Force

    $iscc = Get-Command iscc.exe -ErrorAction SilentlyContinue
    if ($null -eq $iscc) {
        Write-Host "Staging listo en $Stage. iscc.exe no esta instalado; se omite compilacion." -ForegroundColor Yellow
    } else {
        & $iscc.Source (Join-Path $OutputRoot "InventarioArens.iss")
        if ($LASTEXITCODE -ne 0) { throw "Inno Setup fallo con codigo $LASTEXITCODE." }
    }
} finally {
    Pop-Location
}

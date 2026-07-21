param(
    [string] $OutputDir = "dist"
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$cliPath = Join-Path $repoRoot "bin\inventoryarens"

if (-not (Test-Path -LiteralPath $cliPath)) {
    Write-Host "[x] No encontre bin\inventoryarens" -ForegroundColor Red
    exit 1
}

$cliContent = Get-Content -LiteralPath $cliPath -Raw
$versionMatch = [regex]::Match($cliContent, 'VERSION\s*=\s*"(?<version>[0-9.]+)"')
if (-not $versionMatch.Success) {
    Write-Host "[x] No pude extraer VERSION del CLI" -ForegroundColor Red
    exit 1
}

$version = $versionMatch.Groups["version"].Value
$outDirPath = if ([System.IO.Path]::IsPathRooted($OutputDir)) {
    $OutputDir
} else {
    Join-Path $repoRoot $OutputDir
}
$outFile = Join-Path $outDirPath "inventoryarens-toolbox-v$version.zip"
$stage = Join-Path ([System.IO.Path]::GetTempPath()) ("inventoryarens-toolbox-" + [guid]::NewGuid().ToString("N"))

Write-Host "Building inventoryarens-toolbox v$version..."
Write-Host "Output: $outFile"

New-Item -ItemType Directory -Force -Path $outDirPath | Out-Null
if (Test-Path -LiteralPath $outFile) {
    Remove-Item -LiteralPath $outFile -Force
}

New-Item -ItemType Directory -Force -Path $stage | Out-Null

try {
    Copy-Item -LiteralPath (Join-Path $repoRoot "bin\inventoryarens") -Destination (Join-Path $stage "inventoryarens") -Force
    Copy-Item -LiteralPath (Join-Path $repoRoot "bin\inventoryarens.bat") -Destination $stage -Force
    Copy-Item -LiteralPath (Join-Path $repoRoot "bin\inventoryarens.ps1") -Destination $stage -Force

    New-Item -ItemType Directory -Force -Path (Join-Path $stage "systemd") | Out-Null
    Copy-Item -LiteralPath (Join-Path $repoRoot "systemd\inventoryarens-sync.service") -Destination (Join-Path $stage "systemd") -Force
    Copy-Item -LiteralPath (Join-Path $repoRoot "systemd\inventoryarens-sync.timer") -Destination (Join-Path $stage "systemd") -Force
    Copy-Item -LiteralPath (Join-Path $repoRoot "systemd\inventoryarens-printer.service") -Destination (Join-Path $stage "systemd") -Force

    New-Item -ItemType Directory -Force -Path (Join-Path $stage "windows") | Out-Null
    Copy-Item -LiteralPath (Join-Path $repoRoot "windows\install-task.ps1") -Destination (Join-Path $stage "windows") -Force
    Copy-Item -LiteralPath (Join-Path $repoRoot "windows\uninstall-task.ps1") -Destination (Join-Path $stage "windows") -Force

    Copy-Item -LiteralPath (Join-Path $repoRoot "docs\OPERATIONS.md") -Destination (Join-Path $stage "README.md") -Force
    Copy-Item -LiteralPath (Join-Path $repoRoot "docs\TUTORIAL.md") -Destination (Join-Path $stage "TUTORIAL.md") -Force

    $licensePath = Join-Path $repoRoot "LICENSE"
    if (Test-Path -LiteralPath $licensePath) {
        Copy-Item -LiteralPath $licensePath -Destination (Join-Path $stage "LICENSE") -Force
    }

    @"
INVENTARIOARENS Toolbox v$version
================================

INSTALACION RECOMENDADA:

  Windows:
    1. Descomprime el zip.
    2. Abre PowerShell o CMD dentro de la carpeta descomprimida.
    3. Ejecuta: inventoryarens.bat wizard

  Linux:
    1. Descomprime el zip: unzip inventoryarens-toolbox-v$version.zip
    2. cd inventoryarens-toolbox
    3. Ejecuta: ./inventoryarens wizard

MULTIEMPRESA:
  Ejecuta el asistente una vez por cada empresa que quieras sincronizar en esta PC.

  Ejemplo:
    inventoryarens.bat wizard --tenant demo-caracas --user admin@demo.test
    inventoryarens.bat wizard --tenant demo-valencia --user admin@demo.test

DIAGNOSTICO:
  inventoryarens.bat status --tenant demo-caracas
  inventoryarens.bat logs sync --tenant demo-caracas

MAS INFO:
  README.md
  TUTORIAL.md
"@ | Set-Content -LiteralPath (Join-Path $stage "QUICKSTART.txt") -Encoding UTF8

    Compress-Archive -Path (Join-Path $stage "*") -DestinationPath $outFile -Force

    $item = Get-Item -LiteralPath $outFile
    Write-Host ""
    Write-Host "[OK] Zip creado:" -ForegroundColor Green
    Write-Host "  $($item.FullName)"
    Write-Host "  Tamano: $([Math]::Round($item.Length / 1KB, 2)) KB"

    Write-Host ""
    Write-Host "Contenido principal:"
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::OpenRead($outFile).Entries |
        Select-Object -First 20 |
        ForEach-Object { Write-Host ("  " + $_.FullName) }
} finally {
    if (Test-Path -LiteralPath $stage) {
        Remove-Item -LiteralPath $stage -Recurse -Force
    }
}

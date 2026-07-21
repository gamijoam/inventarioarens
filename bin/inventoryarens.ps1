# inventoryarens.ps1 - wrapper Windows PowerShell
#
# Solo invoca el CLI Python con los argumentos recibidos.
# Requiere Python 3.8+ en PATH (o en $env:INVENTORYARENS_PYTHON).
#
# Uso: powershell -File inventoryarens.ps1 install sync
#      powershell -File inventoryarens.ps1 status
#      powershell -File inventoryarens.ps1 logs sync

$ErrorActionPreference = 'Stop'

function Test-PythonCommand {
    param([string]$Command)

    if (-not $Command) { return $false }
    try {
        $null = & $Command --version 2>$null
        return ($LASTEXITCODE -eq 0)
    } catch {
        return $false
    }
}

$python = $env:INVENTORYARENS_PYTHON
if ($python -and -not (Test-PythonCommand $python)) {
    Write-Host "[!] INVENTORYARENS_PYTHON esta configurado pero no ejecuta Python: $python" -ForegroundColor Yellow
    $python = $null
}
if (-not $python) {
    $py = (Get-Command py -ErrorAction SilentlyContinue)
    if ($py -and (Test-PythonCommand 'py')) { $python = 'py' }
}
if (-not $python) {
    $py = (Get-Command python -ErrorAction SilentlyContinue)
    if ($py -and (Test-PythonCommand 'python')) { $python = 'python' }
}
if (-not $python) {
    $py = (Get-Command python3 -ErrorAction SilentlyContinue)
    if ($py -and (Test-PythonCommand 'python3')) { $python = 'python3' }
}
if (-not $python) {
    Write-Host "[x] No se encontro Python. Instala Python 3.8+ desde https://python.org" -ForegroundColor Red
    Write-Host "    o Microsoft Store. Luego ejecuta este script de nuevo." -ForegroundColor Red
    exit 1
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
& $python "$scriptDir\inventoryarens" @args
exit $LASTEXITCODE

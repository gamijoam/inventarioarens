param(
    [string] $PhpBin = "php",
    [switch] $RestartLaragon
)

$ErrorActionPreference = "Stop"

function Write-Ok($Message) {
    Write-Host "OK $Message" -ForegroundColor Green
}

function Write-Info($Message) {
    Write-Host "i  $Message" -ForegroundColor Cyan
}

function Write-Fail($Message) {
    Write-Host "x  $Message" -ForegroundColor Red
}

function Get-LoadedPhpIni {
    $iniOutput = & $PhpBin --ini 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudo ejecutar '$PhpBin --ini'. Salida: $iniOutput"
    }

    $line = $iniOutput | Where-Object { $_ -match "^Loaded Configuration File:\s*(.+)$" } | Select-Object -First 1
    if (-not $line) {
        throw "No se encontro 'Loaded Configuration File' en la salida de php --ini."
    }

    $path = ($line -replace "^Loaded Configuration File:\s*", "").Trim()
    if ([string]::IsNullOrWhiteSpace($path) -or $path -eq "(none)") {
        throw "PHP no tiene php.ini cargado. Crea/configura un php.ini antes de activar GD."
    }

    return $path
}

function Test-GdEnabled {
    $result = & $PhpBin -r "echo extension_loaded('gd') ? 'on' : 'off';" 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudo verificar GD. Salida: $result"
    }

    return (([string] $result).Trim() -eq "on")
}

Write-Host ""
Write-Host "Activador de PHP GD" -ForegroundColor White
Write-Info "PHP bin: $PhpBin"

$phpIni = Get-LoadedPhpIni
Write-Info "php.ini: $phpIni"

if (-not (Test-Path -LiteralPath $phpIni)) {
    throw "El php.ini detectado no existe: $phpIni"
}

if (Test-GdEnabled) {
    Write-Ok "GD ya esta activo."
    exit 0
}

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupPath = "$phpIni.bak-$timestamp"
Copy-Item -LiteralPath $phpIni -Destination $backupPath -Force
Write-Ok "Backup creado: $backupPath"

$content = Get-Content -LiteralPath $phpIni
$changed = $false

$content = $content | ForEach-Object {
    $line = $_
    if ($line -match "^\s*;\s*extension\s*=\s*gd\s*$") {
        $changed = $true
        "extension=gd"
    } elseif ($line -match "^\s*;\s*extension\s*=\s*php_gd2\.dll\s*$") {
        $changed = $true
        "extension=gd"
    } elseif ($line -match "^\s*;\s*extension\s*=\s*gd2\s*$") {
        $changed = $true
        "extension=gd"
    } else {
        $line
    }
}

if (-not $changed) {
    $hasActiveGd = $content | Where-Object { $_ -match "^\s*extension\s*=\s*(gd|gd2|php_gd2\.dll)\s*$" } | Select-Object -First 1
    if (-not $hasActiveGd) {
        $content += ""
        $content += "; INVENTARIOARENS: requerido para imagenes de productos"
        $content += "extension=gd"
        $changed = $true
    }
}

Set-Content -LiteralPath $phpIni -Value $content -Encoding ASCII
Write-Ok "php.ini actualizado."

if ($RestartLaragon) {
    $laragon = Get-Process -Name "laragon" -ErrorAction SilentlyContinue
    if ($laragon) {
        Write-Info "Laragon esta abierto. Reinicia los servicios desde Laragon para que Apache/Nginx/PHP-FPM tome el cambio."
    }
}

if (Test-GdEnabled) {
    Write-Ok "GD quedo activo para CLI."
    Write-Host ""
    Write-Host "Siguiente paso:" -ForegroundColor White
    Write-Host "  Reinicia Laragon o el servidor PHP si estas usando la app web local." -ForegroundColor Gray
    exit 0
}

Write-Fail "GD aun no aparece activo en CLI."
Write-Host ""
Write-Host "Revisa que exista el archivo de extension GD en la carpeta ext de PHP." -ForegroundColor Yellow
Write-Host "php.ini usado: $phpIni" -ForegroundColor Yellow
Write-Host "backup: $backupPath" -ForegroundColor Yellow
exit 1

<#
.SYNOPSIS
  Instala FrankenPHP como servidor del Motor Local de BalanzaPro, reemplazando
  `php artisan serve` (que es monohilo) por un servidor multi-hilo.

.DESCRIPTION
  - Copia FrankenPHP a una carpeta estable (por defecto ProgramData, que no se
    borra al actualizar el Motor).
  - Deja un php.ini con las extensiones que usa la app (pdo_sqlite, sqlite3,
    mbstring, dom, zip, gd, fileinfo, openssl, curl, intl, sodium).
  - Respalda el XML del servicio WinSW y lo reescribe para lanzar frankenphp.
  - Reinicia el servicio.

  Requiere permisos de administrador.

  Descarga de FrankenPHP (Windows x86_64):
    https://github.com/dunglas/frankenphp/releases/download/v1.12.7/frankenphp-windows-x86_64.zip
  Extraer en -FrankenPhpSource antes de correr este script.

.NOTES
  Validado 2026-09-23 en la PC del local (tenant asiamoto).
  FrankenPHP v1.12.7 (PHP 8.5.10 ZTS embebido, Caddy 2.11.4).
  El cambio se pierde si se reinstala/actualiza el Motor (el instalador
  regenera el XML). Volver a correr este script tras cada update.
#>
param(
  [string]$FrankenPhpSource = "$env:TEMP\frankenphp",
  [string]$Destination      = 'C:\ProgramData\InventarioArens\runtime\frankenphp',
  [string]$MotorRoot        = 'C:\Program Files\Sistema de Inventario\Motor',
  [string]$MotorVersion     = '',
  [int]$ListenPort          = 8787,
  [switch]$NoRestart
)

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
  throw 'Este script requiere PowerShell como administrador.'
}

if (-not (Test-Path "$FrankenPhpSource\frankenphp.exe")) {
  throw "No se encontro frankenphp.exe en '$FrankenPhpSource'. Descargar y extraer el zip de FrankenPHP ahi."
}

if (-not $MotorVersion) {
  $MotorVersion = (Get-ChildItem "$MotorRoot\versions" -Directory |
    Sort-Object Name -Descending | Select-Object -First 1).Name
}
$serviceXml = "$MotorRoot\versions\$MotorVersion\service\SistemaInventarioBackend.xml"
$backendPublic = "$MotorRoot\versions\$MotorVersion\backend\public"
if (-not (Test-Path $serviceXml)) { throw "No existe el XML del servicio: $serviceXml" }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'

Write-Host "[frankenphp] version Motor: $MotorVersion"
Write-Host '[frankenphp] deteniendo servicio backend...'
Stop-Service SistemaInventarioBackend -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 3
Get-Process php -ErrorAction SilentlyContinue |
  Where-Object { $_.Path -like "$MotorRoot*" } |
  ForEach-Object { Write-Host "[frankenphp] kill php $($_.Id)"; Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 2

Write-Host "[frankenphp] copiando a $Destination"
New-Item -ItemType Directory -Force -Path $Destination | Out-Null
Copy-Item -Path "$FrankenPhpSource\*" -Destination $Destination -Recurse -Force

$ini = "$Destination\php.ini"
if (-not (Test-Path $ini)) {
  @"
extension_dir = "$Destination\ext"
extension=pdo_sqlite
extension=sqlite3
extension=mbstring
extension=fileinfo
extension=openssl
extension=curl
extension=zip
extension=gd
extension=intl
extension=sodium
opcache.enable=1
opcache.enable_cli=1
memory_limit=512M
"@ | Set-Content -Path $ini -Encoding ASCII
} else {
  (Get-Content $ini) -replace 'extension_dir\s*=\s*".*"', "extension_dir = `"$Destination\ext`"" | Set-Content $ini
}

Write-Host "[frankenphp] respaldando XML del servicio"
Copy-Item $serviceXml "$serviceXml.bak-$stamp" -Force
[xml]$x = Get-Content $serviceXml -Raw
$x.service.executable = "$Destination\frankenphp.exe"
$x.service.arguments  = "php-server --root `"$backendPublic`" --listen 127.0.0.1:$ListenPort"
if (-not ($x.service.env | Where-Object { $_.name -eq 'PHPRC' })) {
  $e = $x.CreateElement('env'); $e.SetAttribute('name','PHPRC'); $e.SetAttribute('value',$Destination)
  $x.service.AppendChild($e) | Out-Null
}
$x.Save($serviceXml)

if (-not $NoRestart) {
  Write-Host '[frankenphp] arrancando servicio'
  Start-Service SistemaInventarioBackend
  Start-Sleep -Seconds 6
  Get-Service SistemaInventarioBackend | Select-Object Status, Name | Format-Table -AutoSize
  try {
    $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 10 "http://127.0.0.1:$ListenPort/up"
    Write-Host ("[frankenphp] health /up = " + $r.StatusCode)
  } catch {
    Write-Host ("[frankenphp] health FALLO: " + $_.Exception.Message)
  }
}

Write-Host "[frankenphp] LISTO. Rollback: restaurar $serviceXml.bak-$stamp y reiniciar el servicio."

<#
.SYNOPSIS
  Configura una instalacion NUEVA de BalanzaPro (100% offline):
  crea el primer administrador + la primera empresa local.

.DESCRIPTION
  Habla directo con el Motor Local (http://127.0.0.1:8787) usando el endpoint
  de bootstrap. Lee el token de C:\ProgramData\InventarioArens\bootstrap.token
  (solo funciona en una base vacia). Se abre con doble clic en
  Configurar-BalanzaPro.cmd.

  Si la instalacion ya tiene usuarios/empresas, no hace nada (no pide admin).
#>

$ErrorActionPreference = 'Stop'

$Api       = 'http://127.0.0.1:8787'
$Base      = "$Api/api"
$TokenFile = 'C:\ProgramData\InventarioArens\bootstrap.token'

function Is-Admin {
  ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator)
}
function Pause-Script { Write-Host ''; Read-Host 'Presiona ENTER para cerrar' | Out-Null }

Write-Host '========================================================='
Write-Host '   BalanzaPro - Configuracion inicial (offline)'
Write-Host '========================================================='
Write-Host ''

# 1) Motor arriba?
try {
  $null = Invoke-WebRequest -UseBasicParsing -TimeoutSec 8 "$Api/up"
} catch {
  Write-Host 'ERROR: el Motor Local no responde en 127.0.0.1:8787.' -ForegroundColor Red
  Write-Host 'Verifica que el servicio "Sistema de Inventario - Backend" este iniciado.'
  Pause-Script; exit 1
}

# 2) Estado del bootstrap (no requiere admin)
$status = $null
try { $status = (Invoke-RestMethod -TimeoutSec 10 "$Base/bootstrap/status").data } catch { }
if ($null -eq $status) {
  Write-Host 'ERROR: no se pudo leer el estado del bootstrap.' -ForegroundColor Red
  Pause-Script; exit 1
}
Write-Host ("Usuarios actuales: {0} | Empresas: {1}" -f $status.user_count, $status.tenant_count)

if (-not $status.can_run) {
  if ($status.user_count -gt 0 -or $status.tenant_count -gt 0) {
    Write-Host ''
    Write-Host 'Esta instalacion YA tiene usuarios/empresas. No hay que configurar nada.' -ForegroundColor Yellow
    Write-Host 'Abri el cliente BalanzaPro e inicia sesion con tu usuario.'
  } elseif (-not $status.enabled) {
    Write-Host ''
    Write-Host 'El bootstrap esta DESHABILITADO (token vacio o ausente).' -ForegroundColor Yellow
    Write-Host ("Revisa el archivo: {0}" -f $TokenFile)
  }
  Pause-Script; exit 0
}

# 3) Hay que configurar: se necesita admin para leer el token -> auto-elevar
if (-not (Is-Admin)) {
  Write-Host ''
  Write-Host 'Se necesita permiso de administrador para leer el token. Reabriendo elevado...' -ForegroundColor Cyan
  Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File', $PSCommandPath)
  exit
}

# 4) Leer token
if (-not (Test-Path -LiteralPath $TokenFile)) {
  Write-Host ("ERROR: no existe {0}" -f $TokenFile) -ForegroundColor Red
  Pause-Script; exit 1
}
$token = (Get-Content -LiteralPath $TokenFile -Raw).Trim()
if ([string]::IsNullOrWhiteSpace($token)) {
  Write-Host 'ERROR: el token de bootstrap esta vacio.' -ForegroundColor Red
  Pause-Script; exit 1
}

Write-Host ''
Write-Host 'Datos del administrador y la empresa:' -ForegroundColor Cyan

$adminName = Read-Host '  Nombre del administrador (ej: Administrador)'
if ([string]::IsNullOrWhiteSpace($adminName)) { $adminName = 'Administrador' }

$adminEmail = Read-Host '  Email del administrador (ej: admin@minegocio.com)'
if ($adminEmail -notmatch '^\S+@\S+\.\S+$') { Write-Host 'Email invalido.' -ForegroundColor Red; Pause-Script; exit 1 }

$pass1 = Read-Host '  Contrasena (minimo 8 caracteres)' -AsSecureString
$pass2 = Read-Host '  Repetir contrasena' -AsSecureString
$p1 = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($pass1))
$p2 = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($pass2))
if ($p1.Length -lt 8) { Write-Host 'La contrasena debe tener al menos 8 caracteres.' -ForegroundColor Red; Pause-Script; exit 1 }
if ($p1 -ne $p2)      { Write-Host 'Las contrasenas no coinciden.' -ForegroundColor Red; Pause-Script; exit 1 }

$tenantName = Read-Host '  Nombre de la empresa (ej: Mi Negocio C.A.)'
if ([string]::IsNullOrWhiteSpace($tenantName)) { $tenantName = 'Mi Empresa' }

$slugDefault = ($tenantName.ToLower() -replace '[^a-z0-9]+','-' -replace '^-+|-+$','')
$tenantSlug  = Read-Host ("  Codigo de la empresa [Enter = {0}]" -f $slugDefault)
if ([string]::IsNullOrWhiteSpace($tenantSlug)) { $tenantSlug = $slugDefault }
$tenantSlug = ($tenantSlug.ToLower() -replace '[^a-z0-9-]+','-' -replace '^-+|-+$','')

$body = @{
  name            = $adminName
  email           = $adminEmail
  password        = $p1
  bootstrap_token = $token
  tenant          = @{ name = $tenantName; slug = $tenantSlug; plan = 'standard' }
} | ConvertTo-Json -Depth 5

Write-Host ''
Write-Host 'Creando instalacion inicial...' -ForegroundColor Cyan
try {
  $resp = Invoke-RestMethod -Method Post -Uri "$Base/bootstrap" -ContentType 'application/json' -Body $body -TimeoutSec 60
  $u = $resp.data.user
  $t = $resp.data.tenant
  Write-Host ''
  Write-Host 'LISTO. Instalacion inicial creada:' -ForegroundColor Green
  Write-Host ("  Usuario: {0} ({1})" -f $u.name, $u.email)
  if ($t) { Write-Host ("  Empresa: {0} ({1})" -f $t.name, $t.slug) }
  Write-Host ''
  Write-Host 'Ahora abri el cliente BalanzaPro e inicia sesion con ese email y contrasena.' -ForegroundColor Green
  Write-Host 'Para crear cajeros/vendedores: cliente Administrativo > Acceso > Usuarios.'
} catch {
  Write-Host ''
  Write-Host ('ERROR al crear: ' + $_.Exception.Message) -ForegroundColor Red
  try {
    $r = $_.Exception.Response
    if ($r) { $sr = New-Object IO.StreamReader($r.GetResponseStream()); Write-Host $sr.ReadToEnd() -ForegroundColor DarkYellow }
  } catch {}
}
Pause-Script

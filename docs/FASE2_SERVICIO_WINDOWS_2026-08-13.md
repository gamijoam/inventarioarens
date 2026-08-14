# Fase 2: servicio Windows dedicado

Fecha: 2026-08-13
Release: 0.2.50

## Resultado

El backend Laravel y el agente de impresion se ejecutan como servicios nativos de Windows,
independientes de las tres aplicaciones Electron:

- `InventarioArensBackend`: `php artisan serve` en `127.0.0.1:8787`.
- `InventarioArensPrinter`: `php artisan printer:serve` en `127.0.0.1:17777`.

Los servicios se registran con inicio automatico y acciones de reinicio de Windows. Electron
detecta `backend-service.json`, verifica el health check y no levanta otro backend ni otro agente.

## Rutas

- Backend y PHP del servicio: `%ProgramData%\InventarioArens\service`.
- BD, tokens, logs y storage compartido: `%APPDATA%\InventarioArens`.
- Configuracion del modo servicio: `%APPDATA%\InventarioArens\backend-service.json`.
- Logs del backend: `%ProgramData%\InventarioArens\service\logs\backend.log`.
- Logs de impresion: `%ProgramData%\InventarioArens\service\logs\printer.log`.

La instalacion o desinstalacion manual del servicio no borra la BD ni los tokens. El desinstalador
de un cliente individual no elimina los servicios compartidos; la limpieza manual solo debe
ejecutarse cuando ya no quede ningun cliente instalado.

## Instalacion normal

Instalar el paquete administrativo, POS o tecnico 0.2.50 como administrador. El instalador:

1. Detiene servicios anteriores.
2. Copia backend y PHP a `ProgramData`.
3. Ejecuta `local:install-sqlite` contra la BD existente.
4. Conserva secretos, tokens y datos de `%APPDATA%\InventarioArens`.
5. Registra y arranca ambos servicios.

No se debe borrar `inventario.sqlite` para actualizar.

## Reparacion manual

Abrir PowerShell como administrador y ejecutar el script incluido en la instalacion:

```powershell
$root = "$env:ProgramFiles\InventarioArens\Sistema-de-Inventario-Administrativo"
powershell.exe -ExecutionPolicy Bypass -File `
  "$root\resources\service\install-backend-service.ps1" `
  -SourceBackendRoot "$root\resources\backend" `
  -SourcePhpRoot "$root\resources\runtime\php"
```

La reparacion es idempotente: vuelve a copiar el runtime, aplica migraciones pendientes y
recrea los servicios sin eliminar la informacion local.

Durante la migracion, `app.key` puede estar almacenado con o sin el prefijo Laravel `base64:`;
el instalador conserva el formato correcto y no antepone el prefijo dos veces.

## Estado y diagnostico

```powershell
Get-Service InventarioArensBackend,InventarioArensPrinter
Test-NetConnection 127.0.0.1 -Port 8787
Test-NetConnection 127.0.0.1 -Port 17777
Get-Content "$env:ProgramData\InventarioArens\service\logs\backend.log" -Tail 80
Get-Content "$env:ProgramData\InventarioArens\service\logs\printer.log" -Tail 80
Get-Content "$env:APPDATA\InventarioArens\backend-service.json"
```

Acciones habituales:

```powershell
Start-Service InventarioArensBackend
Start-Service InventarioArensPrinter
Restart-Service InventarioArensBackend
Restart-Service InventarioArensPrinter
Stop-Service InventarioArensBackend
Stop-Service InventarioArensPrinter
```

Si un servicio aparece detenido, revisar primero su log y luego ejecutar `Restart-Service`. No
eliminar la BD como medida de reparacion.

## Desinstalacion manual del servicio sin borrar datos

```powershell
$root = "$env:ProgramFiles\InventarioArens\Sistema-de-Inventario-Administrativo"
powershell.exe -ExecutionPolicy Bypass -File `
  "$root\resources\service\install-backend-service.ps1" `
  -DataRoot "$env:APPDATA\InventarioArens" -Uninstall
```

Esto detiene y elimina los dos servicios y borra solo el marcador `backend-service.json`. La BD,
los tokens y el storage permanecen.

No ejecutar este paso al desinstalar solo Administrativo, POS o Soporte: los otros clientes
dependen de esos mismos servicios.

## Compatibilidad y limites

En esta entrega el instalador aun incluye backend y PHP dentro del paquete para facilitar la
migracion de instalaciones existentes. El servicio es la unica autoridad de ejecucion; retirar
esos recursos del paquete queda para una fase posterior, despues de validar varias instalaciones.

La implementacion usa `sc.exe` nativo y no agrega NSSM. Las acciones de fallo configuradas por
Windows cubren el reinicio automatico sin depender de un binario de terceros.

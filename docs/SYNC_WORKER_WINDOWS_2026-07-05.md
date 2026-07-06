# Worker de sincronizacion en Windows

Fecha: 2026-07-05

## Objetivo

Se agrego un controlador local para iniciar, detener y revisar el worker continuo de sincronizacion sin escribir comandos largos cada vez.

Archivo agregado:

- `scripts/sync-worker.ps1`
- `scripts/sync-worker.cmd`

Este script envuelve el comando:

```powershell
php artisan sync:daemon
```

## Configuracion requerida

El worker necesita:

- empresa local;
- codigo de nodo local;
- URL del API nube;
- token de API nube.

La forma recomendada es configurar en `.env`:

```env
SYNC_CLOUD_URL=https://dominio-nube.com/api
SYNC_CLOUD_TOKEN=TOKEN_DEL_NODO
```

Tambien se puede pasar en el comando con `-CloudUrl` y `-Token`. En ese caso el token se entrega al proceso como variable temporal y no se escribe en el archivo `.cmd` generado.

## Iniciar worker

Desde la raiz del proyecto:

```powershell
.\scripts\sync-worker.cmd start -TenantSlug demo-caracas -NodeCode LOCAL-CCS-01 -NodeName "Local Caracas" -Interval 30
```

Para probar solo un ciclo y que el proceso termine solo:

```powershell
.\scripts\sync-worker.cmd start -TenantSlug demo-caracas -NodeCode LOCAL-CCS-01 -Interval 5 -Cycles 1
```

Si quieres pasar URL y token manualmente:

```powershell
.\scripts\sync-worker.cmd start -TenantSlug demo-caracas -NodeCode LOCAL-CCS-01 -CloudUrl "https://dominio-nube.com/api" -Token "TOKEN"
```

## Ver estado

```powershell
.\scripts\sync-worker.cmd status
```

El estado indica si el proceso esta activo y muestra el PID.

## Detener worker

```powershell
.\scripts\sync-worker.cmd stop
```

## Archivos operativos

Estado del proceso:

```text
storage/app/sync-worker/sync-worker.pid
```

Comando temporal:

```text
storage/app/sync-worker/sync-worker.cmd
```

Log:

```text
storage/logs/sync-worker.log
```

## Reglas importantes

- El worker no reemplaza al smoke test. Primero se valida local-nube con `scripts/sync-smoke-test.ps1`.
- El worker corre por ciclos usando `sync:daemon`.
- Si ya hay un worker activo, el script no abre otro duplicado.
- Si se reinicia la PC, hay que iniciarlo nuevamente hasta crear una tarea de Windows o servicio.
- La siguiente fase recomendada es crear un inicio automatico desde la app de escritorio o desde el Programador de tareas de Windows.

## Prueba realizada

Se valido:

- sintaxis de `scripts/sync-worker.ps1`;
- comando `status` mediante `scripts/sync-worker.cmd` sin proceso activo;
- pruebas backend del modulo Sync.

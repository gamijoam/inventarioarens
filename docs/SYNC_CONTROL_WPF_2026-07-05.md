# Control visual de sincronizacion en WPF

Fecha: 2026-07-05

## Objetivo

Se agrego una pantalla de escritorio para controlar el worker de sincronizacion local-nube sin depender de comandos manuales.

Desde el centro de modulos, el usuario puede abrir **Sincronizacion** y:

- consultar si el worker esta activo o detenido;
- iniciar el worker continuo;
- ejecutar una sincronizacion manual inmediata;
- detener el worker;
- revisar los ultimos eventos del log local;
- ajustar empresa, nodo local, URL nube, token e intervalo antes de iniciar.

## Archivos agregados

- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerView.xaml`
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerView.xaml.cs`
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerViewModel.cs`

## Integracion

La pantalla queda conectada al `ShellView` principal como otro modulo del centro de modulos.

El control visual no sincroniza directamente contra PostgreSQL. Ejecuta el controlador existente:

```powershell
.\scripts\sync-worker.cmd start
.\scripts\sync-worker.cmd run
.\scripts\sync-worker.cmd status
.\scripts\sync-worker.cmd stop
```

Ese controlador ejecuta `php artisan sync:daemon` en segundo plano cuando se usa `start`, o `php artisan sync:run` cuando se usa `run`.

## Validaciones

La pantalla busca automaticamente la raiz del proyecto usando el archivo `artisan`.

Si no encuentra `scripts\sync-worker.cmd`, muestra un mensaje visible y no intenta iniciar procesos.

Si el worker falla, el error queda visible en la pantalla y tambien queda registrado en:

```txt
storage/logs/sync-worker.log
```

## Ajuste visual y de ejecucion

Actualizacion: 2026-07-06

- La app WPF ejecuta `scripts\sync-worker.cmd` mediante `cmd.exe` con el formato correcto para rutas con espacios o caracteres especiales.
- La pantalla ya no muestra el bloque tecnico completo de consola como mensaje principal cuando el controlador responde.
- El estado se resume como `Activo`, `Detenido`, `Error` o `Sin consultar`.
- Los botones `Iniciar` y `Detener` quedan siempre visibles.
- Se agrego el boton `Sincronizar ahora` para ejecutar un solo ciclo manual.
- La URL nube, token e intervalo se movieron a `Opciones avanzadas de nube` para no ocupar toda la ventana.
- El log queda en un panel amplio y con scroll.
- Se agrego el panel `Estado local de eventos` para consultar al backend Laravel y mostrar conteos reales de `sync_outbox`, `sync_inbox` y ultimos eventos registrados.

## Estado local desde backend

Actualizacion: 2026-07-06

La pantalla ya no depende solo del archivo `storage/logs/sync-worker.log`.

Al actualizar, iniciar, detener o ejecutar `Sincronizar ahora`, WPF tambien consulta:

```txt
GET /api/sync/status
```

Ese endpoint devuelve:

- cantidad de nodos registrados;
- eventos pendientes, procesados y fallidos en `sync_outbox`;
- eventos recibidos, aplicados y fallidos en `sync_inbox`;
- ultimos eventos locales de salida y entrada.

Esto permite diagnosticar si el worker no corre, si hay eventos acumulados, si la nube esta rechazando eventos o si los cambios ya fueron aplicados localmente.

## Configuracion requerida

Para sincronizar contra la nube se necesita configurar:

- `SYNC_CLOUD_URL` en `.env` o escribir la URL en opciones avanzadas;
- `SYNC_CLOUD_TOKEN` en `.env` o escribir el token en opciones avanzadas.

Si falta alguno de esos valores, la app muestra un mensaje en espanol y no ejecuta el ciclo.

## Pruebas realizadas

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- compilacion correcta;
- 0 advertencias;
- 0 errores.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync
```

Resultado:

- 12 pruebas pasadas;
- 69 aserciones.

```powershell
.\scripts\sync-worker.cmd status
```

Resultado:

- el controlador respondio correctamente;
- estado actual: detenido.

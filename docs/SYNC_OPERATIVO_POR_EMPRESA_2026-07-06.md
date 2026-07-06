# Sincronizacion operativa por empresa

Fecha: 2026-07-06

## Objetivo

La sincronizacion no debe mostrarse al usuario como un modulo tecnico basado en `tenant`, `outbox`, `inbox` o `node_code`.

Para operacion real, la app debe comportarse asi:

1. El usuario inicia sesion.
2. Selecciona una empresa.
3. La app revisa si esa empresa esta lista localmente.
4. Si no esta lista, se muestra un aviso de sincronizacion inicial.
5. Cuando termina la descarga base, se habilitan los modulos.
6. La sincronizacion queda activa o disponible en segundo plano.

## Criterio funcional

El estado de sincronizacion se maneja por combinacion:

```txt
instalacion local + empresa seleccionada
```

Esto permite que una misma computadora pueda trabajar con varias empresas o sedes sin mezclar datos.

Ejemplo:

```txt
LOCAL-PC-01 + demo-valencia-norte = sincronizado
LOCAL-PC-01 + demo-valencia-sur = pendiente
LOCAL-PC-01 + demo-caracas = error
```

## Fases

### Fase 1 - Estado simple en el centro de modulos

Implementada.

- Se oculta la sincronizacion como tarjeta principal.
- Se agrega un semaforo en el centro de modulos.
- Se agrega el boton `Sincronizar ahora`.
- El detalle tecnico queda separado del flujo normal.

Estados visuales:

- Verde: sincronizado o activo.
- Amarillo: detenido o sincronizando.
- Rojo: error o no configurado.
- Gris: sin consultar.

### Fase 2 - Estado por instalacion local y empresa

Implementada.

La app ya no interpreta la sincronizacion solo como un `tenant` generico. Ahora se registra el estado por:

```txt
empresa seleccionada + instalacion local
```

Esto permite que una misma empresa se abra en varias computadoras sin bloquearlas entre si, y tambien permite que una misma computadora trabaje con varias empresas con estados independientes.

Ejemplo:

```txt
PC-MOSTRADOR-01 + demo-valencia = sincronizado
PC-ADMIN-01 + demo-valencia = pendiente
PC-MOSTRADOR-01 + demo-caracas = sincronizado
```

La validacion operativa queda lista para que la siguiente fase muestre avisos de sincronizacion inicial sin bloquear globalmente a otros equipos.

### Fase 3 - Sincronizacion inicial guiada

Implementada como primera version operativa.

Cuando el usuario entra al centro de modulos, la app consulta el estado de esta combinacion:

```txt
empresa seleccionada + instalacion local
```

Si la empresa esta pendiente, en sincronizacion, con advertencias o con error, se muestra un aviso visible en el centro de modulos con una accion directa:

```txt
Sincronizar empresa
```

Ademas, al abrir el panel principal, si la empresa esta pendiente, sin consultar, no configurada o en error para esa computadora, la app muestra una confirmacion:

```txt
Quieres sincronizarla ahora para descargar productos, precios, cajas y permisos?
```

Si el usuario acepta, se ejecuta un ciclo manual de sincronizacion. Si no acepta, el aviso queda visible para hacerlo despues.

La sincronizacion inicial no bloquea a otras computadoras. Si otra PC ya tiene la misma empresa lista, puede seguir trabajando. El estado pendiente afecta solo a la instalacion local actual.

Estados del aviso:

- `pending`: la empresa necesita sincronizacion inicial en esta PC.
- `syncing`: la empresa se esta preparando en esta PC.
- `ready`: se oculta el aviso y queda el semaforo en verde.
- `warning`: se muestra advertencia operativa con detalle.
- `error`: se muestra error y permite reintentar.

### Fase 4 - Vista tecnica solo para soporte

Pendiente.

La vista tecnica con outbox, inbox, log y node code debe quedar disponible solo para soporte o administradores tecnicos.

### Fase 5 - Conflictos y reglas de prioridad

Pendiente.

Se definiran reglas por tipo de dato:

- precios: prioridad administrador o ultima escritura, segun configuracion;
- ventas: nunca se sobrescriben, solo se replican;
- inventario: conciliacion por movimientos, no por reemplazo directo de stock;
- usuarios/permisos: prioridad nube.

### Fase 6 - Arranque real de una base local vacia

Pendiente.

Una instalacion local vacia no debe depender de seeders demo para operar. El flujo real debe ser:

1. El usuario escribe correo y clave.
2. La app consulta la nube para saber a que empresas pertenece.
3. Al seleccionar empresa, se crea una ficha local minima de esa empresa.
4. El worker descarga catalogo, precios, almacenes, cajas, permisos y datos necesarios.
5. Cuando termina, se marca la empresa como lista para esa computadora.

Los seeders solo se usan para pruebas y demostraciones. No son el mecanismo de sincronizacion real.

### Fase 7 - Cambios manuales fuera del sistema

Definicion operativa.

Los cambios hechos directamente en HeidiSQL o PostgreSQL no generan eventos de sincronizacion, porque no pasan por Laravel ni por sus reglas de auditoria. Para que un cambio suba a la nube debe hacerse desde:

- una API del sistema;
- una pantalla del sistema;
- un comando controlado que cree el evento en `sync_outbox`.

No se recomienda usar triggers SQL para cambios comerciales como precios, porque se perderia contexto de usuario, permisos, motivo, moneda, tasa y reglas de conflicto.

## Implementacion actual

Archivos modificados:

- `database/migrations/2026_07_06_130000_create_sync_tenant_readiness_table.php`
- `app/Modules/Sync/Services/SyncReadinessService.php`
- `app/Modules/Sync/Requests/SyncReadinessRequest.php`
- `app/Modules/Sync/Controllers/SyncController.php`
- `app/Modules/Sync/Services/SyncWorkerService.php`
- `app/Modules/Sync/Commands/RunSyncCommand.php`
- `app/Modules/Sync/Commands/RunSyncDaemonCommand.php`
- `app/Modules/Sync/routes.php`
- `scripts/sync-worker.ps1`
- `desktop/InventoryDesktop/ShellView.xaml`
- `desktop/InventoryDesktop/ShellView.xaml.cs`
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerView.xaml.cs`

El centro de modulos ahora muestra el estado operativo de sincronizacion y permite ejecutar un ciclo manual sin abrir la pantalla tecnica.
Si la empresa aun no esta lista en esa computadora, muestra un aviso de sincronizacion inicial dentro del centro de modulos.

Actualizacion 2026-07-06:

- Cuando la nube recibe eventos locales por `POST /api/sync/events/push`, ahora los aplica inmediatamente contra su propia base de datos.
- El mismo evento recibido se espeja en `sync_outbox` de la nube con `origin_node_id`, para que otras computadoras puedan descargarlo sin reenviarlo al nodo que lo origino. Si el evento falla al aplicarse, no se retransmite.
- Esto corrige el caso donde un precio editado correctamente desde el sistema local quedaba como enviado, pero no cambiaba en la base PostgreSQL del VPS.
- Si el precio se edita manualmente en la tabla `products` por HeidiSQL, no se genera outbox y por tanto no se sincroniza.
- Ajuste posterior: la nube aplica por UUID los eventos que acaba de recibir. Esto evita que eventos antiguos en `sync_inbox` bloqueen un cambio nuevo, como un `product.updated` de precio.
- Si el VPS ya tenia eventos recibidos antes de este ajuste, se pueden procesar manualmente con `php artisan sync:apply-inbox demo-valencia --limit=200`.

APIs agregadas:

- `GET /api/sync/local-readiness?installation_code=LOCAL-PC-01`
- `POST /api/sync/local-readiness`

Campos principales:

- `tenant_id`: empresa actual.
- `installation_code`: codigo estable de la computadora o instalacion local.
- `node_code`: nodo usado por el worker.
- `status`: `pending`, `syncing`, `ready`, `warning` o `error`.
- `last_success_at`: ultima sincronizacion correcta.
- `initial_sync_completed_at`: primera sincronizacion base completada.

## Pruebas realizadas

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncSchemaTest.php tests\Feature\Sync\SyncApiTest.php tests\Feature\Sync\SyncWorkerCommandTest.php
```

Resultado:

- 12 pruebas pasadas;
- 85 aserciones.

Verificacion posterior:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncApiTest.php tests\Feature\Sync\SyncApplyInboxCommandTest.php tests\Feature\Sync\SyncWorkerCommandTest.php
```

Resultado:

- 14 pruebas pasadas;
- 100 aserciones.

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

Implementada como primera version operativa con instalador externo.

Una instalacion local vacia no debe depender de seeders demo para operar. El flujo real debe ser:

1. El usuario escribe correo y clave.
2. La app consulta la nube para saber a que empresas pertenece.
3. Al seleccionar empresa, se crea una ficha local minima de esa empresa.
4. El worker descarga catalogo, precios, almacenes, cajas, permisos y datos necesarios.
5. Cuando termina, se marca la empresa como lista para esa computadora.

Los seeders solo se usan para pruebas y demostraciones. No son el mecanismo de sincronizacion real.

Actualizacion 2026-07-06:

- Se creo una herramienta WPF independiente del programa principal: `desktop/InventorySyncInstaller`.
- Esta herramienta se ejecuta antes del sistema principal y no requiere que la BD local ya tenga empresas o usuarios.
- Flujo del instalador:
  1. el tecnico escribe URL de nube, correo y clave;
  2. el instalador consulta `POST /api/auth/tenants`;
  3. el tecnico selecciona la empresa;
  4. el instalador inicia sesion contra la nube;
  5. solicita un token con `POST /api/sync/tokens`;
  6. corre migraciones locales;
  7. prepara la empresa y usuario local con `php artisan sync:prepare-local`;
  8. guarda la configuracion en `storage/app/sync-worker/sync-config.json`;
  9. ejecuta una primera sincronizacion;
  10. deja activo el worker automatico para esa empresa.
- El sistema principal queda para operacion diaria. La preparacion inicial queda fuera del login normal.
- El instalador no crea productos demo. Solo crea empresa, usuario y permisos locales minimos para permitir el primer acceso.
- Los datos comerciales deben venir de la nube mediante eventos de sincronizacion.

### Fase 7 - Cambios manuales fuera del sistema

Definicion operativa.

Los cambios hechos directamente en HeidiSQL o PostgreSQL no generan eventos de sincronizacion, porque no pasan por Laravel ni por sus reglas de auditoria. Para que un cambio suba a la nube debe hacerse desde:

- una API del sistema;
- una pantalla del sistema;
- un comando controlado que cree el evento en `sync_outbox`.

No se recomienda usar triggers SQL para cambios comerciales como precios, porque se perderia contexto de usuario, permisos, motivo, moneda, tasa y reglas de conflicto.

### Fase 8 - Configuracion para tecnicos y automatizacion

Implementada como primera version operativa.

La sincronizacion automatica existe mediante el worker local. Cuando se inicia, ejecuta ciclos continuos cada 30 segundos por defecto. Ese intervalo puede cambiarse en la pantalla de sincronizacion.

Puntos importantes:

- La sincronizacion manual ejecuta un solo ciclo.
- La sincronizacion automatica queda corriendo en segundo plano hasta detenerla.
- Cada empresa puede tener su propia URL de nube, token, nodo e intervalo.
- El token global del `.env` queda como respaldo, pero para operacion real se recomienda guardar token por empresa.
- El worker ya no usa un unico proceso global para todas las empresas; ahora el PID y el log se separan por empresa.

Archivos locales usados:

- `storage/app/sync-worker/sync-config.json`: configuracion local por empresa.
- `storage/app/sync-worker/sync-worker-{empresa}.pid`: proceso activo por empresa.
- `storage/logs/sync-worker-{empresa}.log`: log por empresa.

Objetivo para la siguiente fase:

- Crear una experiencia mas guiada tipo asistente tecnico:
  1. probar conexion local;
  2. probar conexion nube;
  3. pegar token o solicitarlo;
  4. guardar configuracion;
  5. iniciar sincronizacion automatica;
  6. mostrar estado con semaforo simple.

Actualizacion posterior:

- Se agrego el asistente tecnico dentro del modulo `Sincronizacion`.
- El tecnico ya no necesita entrar al VPS para emitir tokens en una instalacion normal.
- Flujo del asistente:
  1. escribe URL de la API nube;
  2. escribe correo y contrasena del gerente;
  3. consulta empresas activas asociadas a ese correo;
  4. selecciona la empresa;
  5. genera un token de sincronizacion para esa empresa;
  6. guarda URL, token, nodo e intervalo en `storage/app/sync-worker/sync-config.json`.
- Si un mismo correo pertenece a varias empresas, el selector muestra todas las empresas activas que devuelve `POST /api/auth/tenants`.
- El comando `php artisan sync:issue-token` sigue existiendo como herramienta administrativa, pero para uso de campo se recomienda el asistente.

## Implementacion actual

Archivos modificados:

- `app/Modules/Sync/Commands/PrepareLocalTenantCommand.php`
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
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerView.xaml`
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerView.xaml.cs`
- `desktop/InventoryDesktop/Modules/Sync/SyncWorkerViewModel.cs`
- `desktop/InventoryDesktop.slnx`
- `desktop/InventorySyncInstaller/InventorySyncInstaller.csproj`
- `desktop/InventorySyncInstaller/App.xaml`
- `desktop/InventorySyncInstaller/App.xaml.cs`
- `desktop/InventorySyncInstaller/MainWindow.xaml`
- `desktop/InventorySyncInstaller/MainWindow.xaml.cs`

El centro de modulos ahora muestra el estado operativo de sincronizacion y permite ejecutar un ciclo manual sin abrir la pantalla tecnica.
Si la empresa aun no esta lista en esa computadora, muestra un aviso de sincronizacion inicial dentro del centro de modulos.

Actualizacion 2026-07-06:

- Cuando la nube recibe eventos locales por `POST /api/sync/events/push`, ahora los aplica inmediatamente contra su propia base de datos.
- El mismo evento recibido se espeja en `sync_outbox` de la nube con `origin_node_id`, para que otras computadoras puedan descargarlo sin reenviarlo al nodo que lo origino. Si el evento falla al aplicarse, no se retransmite.
- Esto corrige el caso donde un precio editado correctamente desde el sistema local quedaba como enviado, pero no cambiaba en la base PostgreSQL del VPS.
- Si el precio se edita manualmente en la tabla `products` por HeidiSQL, no se genera outbox y por tanto no se sincroniza.
- Ajuste posterior: la nube aplica por UUID los eventos que acaba de recibir. Esto evita que eventos antiguos en `sync_inbox` bloqueen un cambio nuevo, como un `product.updated` de precio.
- Si el VPS ya tenia eventos recibidos antes de este ajuste, se pueden procesar manualmente con `php artisan sync:apply-inbox demo-valencia --limit=200`.
- La pantalla de sincronizacion ahora permite guardar configuracion local por empresa y el worker usa PID/log separado por empresa.

Actualizacion de interfaz:

- El modulo `Sincronizacion` se reorganizo para uso operativo:
  - semaforo de estado como primer elemento visible;
  - acciones rapidas agrupadas: sincronizar ahora, iniciar automatico, detener y asistente tecnico;
  - configuracion avanzada separada para soporte;
  - eventos locales y log tecnico en paneles de diagnostico.
- El asistente tecnico se redisenio como flujo de instalacion:
  - credenciales del gerente;
  - empresa a sincronizar;
  - identificacion local del equipo;
  - boton principal `Configurar sincronizacion`.
- El objetivo es que el cliente o tecnico no tenga que copiar tokens ni ejecutar comandos manuales.

APIs agregadas:

- `POST /api/sync/tokens`
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

Verificacion de automatizacion por empresa:

```powershell
scripts\sync-worker.cmd status -TenantSlug demo-valencia
```

Resultado:

- el controlador responde correctamente;
- muestra estado por empresa;
- usa log especifico `storage/logs/sync-worker-demo-valencia.log`.

Verificacion del asistente tecnico:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncTokenApiTest.php tests\Feature\Sync\SyncWorkerCommandTest.php
```

Resultado:

- 7 pruebas pasadas;
- 39 aserciones.

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

Verificacion del instalador externo:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventorySyncInstaller\InventorySyncInstaller.csproj
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Sync\SyncTokenApiTest.php
```

Resultado:

- 8 pruebas pasadas;
- 47 aserciones.

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventoryDesktop\InventoryDesktop.csproj --no-restore
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

## Ajuste visual del configurador tecnico

Se redisenó la interfaz del instalador externo para que el tecnico no vea comandos ni mensajes internos por defecto.

Cambios principales:

- La pantalla ahora se presenta como `Configurador del Sistema de Inventario`.
- Los campos quedan organizados por flujo: servidor, administrador, empresa, computadora y frecuencia.
- El proceso se muestra como pasos claros: validar acceso, obtener empresas, preparar base local, guardar configuracion, sincronizar datos iniciales, activar sincronizacion automatica y finalizar instalacion.
- Los detalles tecnicos y logs quedan ocultos bajo el boton `Ver detalles tecnicos`.
- Al finalizar, aparece el boton `Abrir Sistema de Inventario` para iniciar la aplicacion principal.

## Ajuste de mensajes de conexion del configurador

Se mejoro el manejo de errores del configurador externo para que, cuando la API de nube no responda, el tecnico vea un mensaje en español indicando que debe revisar URL, servidor Laravel y puerto abierto. El detalle tecnico queda oculto bajo `Ver detalles tecnicos`.

## Ajuste contra bloqueo de log del worker

Se corrigio el caso donde el configurador podia fallar durante la sincronizacion inicial si el archivo `storage/logs/sync-worker-{empresa}.log` estaba en uso por otro proceso.

Cambios:

- El configurador detiene cualquier worker previo de la empresa antes de ejecutar la sincronizacion inicial.
- El worker escribe logs de forma tolerante: si el archivo esta ocupado, muestra un aviso y la sincronizacion continua.
- Esto evita errores de reinstalacion o reconfiguracion cuando una sincronizacion automatica anterior quedo activa.

Verificacion:

```powershell
& 'C:\Program Files\dotnet\dotnet.exe' build desktop\InventorySyncInstaller\InventorySyncInstaller.csproj --no-restore -o .build\inventory-sync-installer-build-check
```

Resultado:

- compilacion correcta;
- 0 errores;
- 0 advertencias.

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Sync\SyncTokenApiTest.php
```

Resultado:

- 8 pruebas pasadas;
- 47 aserciones.

## Ajuste de instalacion local por empresa

Se detecto y corrigio un punto importante del configurador externo:

- Antes, el boton de buscar empresas consultaba por correo y podia mostrar empresas aunque la contrasena estuviera mal.
- Ahora, el configurador valida correo y contrasena contra cada empresa candidata antes de mostrarla.
- Si la contrasena es incorrecta, no se listan empresas.

Comportamiento actual:

- La instalacion configura una empresa por vez.
- Si una computadora necesita trabajar con otra empresa, el tecnico debe volver a ejecutar el configurador, buscar con el usuario autorizado y seleccionar la empresa correspondiente.
- Cada empresa queda guardada con su propio token, nodo e intervalo de sincronizacion.

Mejora en el worker:

- El worker automatico ahora se inicia como proceso PHP directo para que el archivo PID corresponda al proceso real.
- Esto facilita detenerlo correctamente antes de reconfigurar, limpiar la base o reinstalar una empresa.
- Si la API de nube no esta activa en la URL configurada, el worker no podra descargar productos ni cambios iniciales.

## Foto inicial de catalogo desde la nube

Se corrigio el caso de una computadora local limpia donde la empresa se preparaba, pero solo bajaba la identidad de empresa y no bajaban productos, almacenes, cajas, tasas ni precios.

La sincronizacion sigue siendo por empresa e instalacion local, pero ahora el worker detecta si el catalogo local esta incompleto. Cuando eso ocurre, al registrar el nodo contra la nube solicita una foto inicial.

La nube genera eventos dirigidos a esa computadora para:

- sucursales;
- almacenes;
- tipos de tasa;
- tasas;
- metodos de pago;
- listas de precio;
- productos;
- precios por producto;
- movimientos de stock;
- seriales e IMEI;
- cajas fisicas.

Punto importante: esto no es un seed local. Los datos salen de la base de datos de la nube y se bajan al local mediante el mismo canal de sincronizacion.

Los movimientos de stock bajados por foto inicial se registran localmente como `sync_snapshot`. Eso permite reconstruir disponibilidad local sin confundirlo con una entrada manual hecha por el operador.

Si una computadora ya estaba marcada como sincronizada pero no tiene catalogo base, el worker vuelve a pedir la foto inicial. Asi evitamos que una base local quede en estado `ready` sin productos.

Documento especifico: `docs/SYNC_FOTO_INICIAL_CATALOGO_2026-07-06.md`.

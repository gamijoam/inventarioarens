# Validacion Windows de los clientes Electron

**Proyecto:** INVENTARIOARENS
**Clientes:** `Sistema de Inventario (Administrativo)`, `POS` y `Soporte Tecnico`
**Fecha de referencia:** 2026-08-07
**Objetivo:** guia operativa para que una persona o una IA valide, instale, ejecute y diagnostique los clientes Windows.

## 1. Alcance

Esta guia corresponde a los clientes Electron actuales, empaquetados con NSIS:

- `Sistema de Inventario (Administrativo)`
- `POS`
- `Soporte Tecnico Inventario Arens`

No debe confundirse con `docs/INSTALADOR_WINDOWS_SQLITE_EXE.md`, que documenta el instalador Windows Laravel anterior.

Los clientes Electron actuales incluyen dentro del instalador:

- frontend React compilado;
- Electron;
- backend Laravel y `vendor/`;
- PHP portable 8.4.24 NTS x64;
- runtime local SQLite.

La PC del usuario no necesita instalar PHP, Composer, PostgreSQL, Redis, Node.js ni pnpm para ejecutar el `.exe` ya construido.

## 2. Arquitectura que debe validarse

Cada cliente Electron ejecuta su propio renderer y ambos comparten el backend local y la base SQLite de la computadora.

El backend compartido no pertenece a ninguna ventana: el primer cliente lanza un supervisor Electron
desacoplado y registra su lease en `runtime-leases/`. Administrativo y POS mantienen un heartbeat mientras
estan abiertos; el supervisor conserva Laravel y el worker de sync activos mientras exista un lease fresco.
Los leases vencen tras un cierre abrupto y, cuando no queda ninguno durante el período idle, el supervisor
detiene Laravel. Esto permite cerrar Administrativo sin interrumpir una venta activa en POS.

```text
Administrativo renderer  -> http://127.0.0.1:8788
POS renderer             -> http://127.0.0.1:8789
Tecnico renderer         -> http://127.0.0.1:8790
                                      |
                                      v
                            Laravel local -> http://127.0.0.1:8787
                                      |
                                      v
                            SQLite compartida
```

Puertos esperados:

| Servicio                | Puerto | Uso                                               |
| ----------------------- | -----: | ------------------------------------------------- |
| API Laravel local       |   8787 | autenticacion, inventario, caja, POS y sync local |
| Renderer Administrativo |   8788 | interfaz administrativa                           |
| Renderer POS            |   8789 | interfaz de punto de venta                        |
| Renderer Tecnico        |   8790 | consola local de soporte                          |

Datos persistentes compartidos:

```text
%APPDATA%\InventarioArens\inventario.sqlite
%APPDATA%\InventarioArens\storage\
%APPDATA%\InventarioArens\logs\api.log
%APPDATA%\InventarioArens\logs\sync.log
%APPDATA%\InventarioArens\runtime-leases\
%APPDATA%\InventarioArens\.runtime-supervisor.pid
```

Los datos de configuracion de Electron estan aislados por cliente:

```text
%APPDATA%\InventarioArens-Administrativo\
%APPDATA%\InventarioArens-POS\
%APPDATA%\InventarioArens-Soporte\
```

No borrar `inventario.sqlite` durante una prueba: contiene los datos locales.

## 3. Requisitos de la PC Windows

### 3.1 Para ejecutar el instalador

- Windows 10 u 11 de 64 bits.
- Usuario con permiso para instalar aplicaciones.
- Espacio libre suficiente para la aplicacion y los datos locales.
- Microsoft Visual C++ Redistributable 2015-2022 x64 si Windows informa que falta `VCRUNTIME140_1.dll` u otra DLL de Visual C++.
- Conexion a Internet solo para descargar el instalador, iniciar sesion en la nube o sincronizar.

No instalar manualmente PHP, Composer, PostgreSQL ni Node.js para ejecutar los clientes empaquetados.

### 3.2 Para construir el instalador desde el repositorio

- Windows 10/11 x64.
- Git.
- Node.js 20.19 o superior.
- Corepack/pnpm 9.15.9.
- `tar` disponible en el `PATH`. Windows 10/11 normalmente ya lo incluye.
- Conexion a Internet para descargar Electron, dependencias pnpm y PHP portable.
- Permisos de escritura en el repositorio y en `build/`.

Comprobar herramientas desde PowerShell:

```powershell
node --version
corepack --version
tar --version
git --version
```

## 4. Construir los instaladores NSIS

Desde PowerShell, en la raiz del repositorio:

```powershell
cd frontend
corepack enable
corepack prepare pnpm@9.15.9 --activate
pnpm install --frozen-lockfile
```

Ejecutar primero las verificaciones:

```powershell
pnpm test
pnpm exec tsc --noEmit
pnpm exec eslint electron/app-config.cjs electron/backend-runtime.cjs electron/renderer-server.cjs electron/main.cjs
```

Construir los tres clientes:

```powershell
pnpm run electron:build:admin
pnpm run electron:build:pos
pnpm run electron:build:technician
```

Aunque el script se llama `electron:prepare:php:linux`, el script detecta `process.platform` y en Windows prepara el PHP portable Windows definido en `stage-electron-backend.cjs`:

```text
PHP 8.4.24 NTS Win32-vs17-x64
SHA-256 esperado:
86470a30cbbaeafb259e727dfa5cd336f2f3f0a462cd6f8e3eac00fdbded13cb
```

Resultados esperados:

```text
frontend\release\admin\*.exe
frontend\release\pos\*.exe
```

El nombre exacto del `.exe` POS puede depender de la version de electron-builder. Revisar el contenido de cada carpeta `release`.

Verificar hashes de los artefactos:

```powershell
Get-ChildItem .\release\admin\*.exe | Get-FileHash -Algorithm SHA256
Get-ChildItem .\release\pos\*.exe | Get-FileHash -Algorithm SHA256
```

## 5. Instalar y ejecutar

1. Cerrar cualquier version anterior de Administrativo y POS.
2. Instalar el `.exe` Administrativo.
3. Instalar el `.exe` POS.
4. Instalar el `.exe` Soporte Tecnico.
5. Ejecutar primero Administrativo.
6. Esperar hasta que aparezca la pantalla de login.
7. Ejecutar POS.
8. Ejecutar Soporte Tecnico cuando se necesite administrar la instalacion local.
9. Iniciar sesion en Administrativo/POS con un usuario y tenant validos.

Los instaladores son NSIS `oneClick`. La ruta final debe verificarse en el acceso directo o en la configuracion de Windows; no asumir que el instalador permitira cambiarla durante el flujo one-click.

La primera ejecucion crea automaticamente la estructura SQLite y ejecuta `local:install-sqlite`.

### 5.1 Instalacion nueva sin usuarios

Una SQLite nueva no contiene usuarios. En ese caso no se debe interpretar la pantalla de login como un fallo del instalador.

Para una instalacion inicial:

1. Confirmar que existe `%APPDATA%\InventarioArens\bootstrap.token`.
2. Abrir temporalmente en un navegador de la misma PC:

```text
http://127.0.0.1:8788/setup
```

3. Usar el `APP_BOOTSTRAP_TOKEN` correspondiente al runtime local.
4. Crear el primer Platform Admin y, si corresponde, la empresa inicial.
5. Cerrar el navegador y entrar desde Administrativo o POS.

Para una empresa ya existente en la nube, no crear una empresa duplicada con `/setup`. Usar el flujo de vinculacion/soporte local y configurar el tenant y token de sync de esa empresa.

## 6. Criterios de validacion funcional

La IA o persona que valida debe marcar cada punto como `PASS`, `FAIL` o `BLOCKED`, incluyendo evidencia.

### 6.1 Arranque

- [ ] Administrativo abre sin ventana negra, error de Electron ni pantalla en blanco.
- [ ] POS abre sin ventana negra, error de Electron ni pantalla en blanco.
- [ ] Ambos pueden ejecutarse al mismo tiempo.
- [ ] El segundo cliente no intenta ejecutar migraciones SQLite concurrentes.
- [ ] Cerrar y volver a abrir cada cliente no borra la sesion ni los datos locales indebidamente.
- [ ] Un segundo lanzamiento del mismo cliente respeta el single-instance lock y enfoca la ventana existente.

### 6.2 API y persistencia

Con ambos clientes abiertos, ejecutar:

```powershell
Test-NetConnection 127.0.0.1 -Port 8787
Test-NetConnection 127.0.0.1 -Port 8788
Test-NetConnection 127.0.0.1 -Port 8789
```

Esperado:

- `TcpTestSucceeded : True` en los tres puertos.
- `http://127.0.0.1:8787/up` responde correctamente.
- La base `%APPDATA%\InventarioArens\inventario.sqlite` existe y tiene un tamaño mayor que cero.
- Crear un registro en Administrativo y cerrar/abrir el cliente conserva el registro.
- El mismo dato visible en POS proviene de la misma SQLite local.

### 6.3 Autenticacion y tenants

- [ ] Login con credenciales validas funciona.
- [ ] Login con password incorrecto muestra error y no entra.
- [ ] El tenant seleccionado se conserva en las solicitudes.
- [ ] Usuario sin permiso no puede ejecutar la accion protegida.
- [ ] El build empaquetado no muestra `Dev (auth bypass)`.
- [ ] Cerrar sesion elimina la sesion local y devuelve a login.
- [ ] Soporte Tecnico abre directamente el Centro tecnico local.

### 6.4 Administrativo

- [ ] Dashboard carga.
- [ ] Productos, marcas, categorias y almacenes cargan.
- [ ] Crear o editar un producto funciona.
- [ ] El stock y el historial de movimientos se muestran correctamente.
- [ ] Un cambio administrativo se refleja al volver a abrir POS.
- [ ] La navegacion restringida del POS no se mezcla con las rutas administrativas.

### 6.5 POS

- [ ] Bootstrap POS carga caja, almacen, metodos de pago, listas de precio y tasa.
- [ ] Buscar producto por nombre, SKU o codigo de barras funciona.
- [ ] Agregar producto, cambiar cantidad y eliminar linea funciona.
- [ ] Seleccionar cliente funciona.
- [ ] Pago USD funciona.
- [ ] Pago VES funciona con tasa visible y snapshot de la tasa.
- [ ] Venta mixta USD/VES calcula correctamente el restante y el vuelto.
- [ ] `F3` abre busqueda.
- [ ] `F4` abre cliente.
- [ ] `F6` envia la venta a espera.
- [ ] `F10` ejecuta cobro sin mostrar `CSRF: Origin not in allowlist`.
- [ ] Confirmar una venta descuenta el stock local.
- [ ] Un producto serializado marca correctamente el IMEI/unidad como vendido.
- [ ] El recibo se puede consultar despues del cobro.
- [ ] Una venta no se duplica si se reintenta el mismo checkout.

### 6.6 Uso simultaneo

- [ ] Administrativo y POS permanecen abiertos al mismo tiempo.
- [ ] Crear o modificar catalogo desde Administrativo no cierra POS.
- [ ] Cobrar desde POS no cierra Administrativo.
- [ ] Ambos clientes pueden hacer solicitudes a `127.0.0.1:8787`.
- [ ] No aparece error de CSRF al ejecutar acciones desde cualquiera de los dos clientes.
- [ ] Si ambos arrancan al mismo tiempo, solo uno ejecuta la instalacion SQLite.

### 6.8 Modo LAN opcional

El modo LAN esta desactivado por defecto. Para probarlo:

1. Abrir Soporte Tecnico en la PC anfitriona.
2. Activar `Modo LAN` en la tarjeta de la instalacion.
3. Cerrar y volver a abrir los clientes Electron de la PC anfitriona.
4. En Windows Firewall permitir TCP `8787,8788,8789` solo en el perfil `Private` y `LocalSubnet`.
5. Desde otra PC de la misma red abrir `http://IP-ANFITRION:8788` para Administrativo o `:8789` para POS.
6. Iniciar sesion con credenciales normales.

No abrir el puerto en el perfil `Public`. No compartir `inventario.sqlite` por SMB. El host debe
mantener un cliente Electron abierto mientras existan clientes remotos, porque el supervisor conserva
Laravel activo mediante los leases de los clientes abiertos.

### 6.7 Sincronizacion con la nube

La sincronizacion es opcional para una prueba puramente local. Si se valida sync:

- [ ] El tenant local esta vinculado al tenant correcto de la nube.
- [ ] El token de sync corresponde a ese tenant y nodo.
- [ ] `SYNC_CLOUD_URL` apunta a `https://app.miinventariofacil.com/api`.
- [ ] Una accion local genera/encola el evento esperado.
- [ ] `Sync now` o el worker procesa el evento sin error.
- [ ] Un cambio de catalogo o stock llega al VPS.
- [ ] Un cambio de nube llega al nodo local.
- [ ] Un evento ya procesado no se duplica.
- [ ] Si la nube esta temporalmente fuera de servicio, la operacion local no corrompe SQLite y el evento queda pendiente para retry.

No reutilizar un token de sync de otra empresa. El aislamiento de tokens por tenant es intencional.

### 6.7.1 Vinculacion de un grupo completo en Windows

Este flujo instala todas las empresas de un grupo en una misma computadora Windows. El backend
emite un token independiente para cada tenant; el codigo grupal no crea un token global y no debe
copiarse manualmente al archivo de configuracion.

#### Requisitos

- El backend cloud ya tiene desplegadas las migraciones de pairing grupal y mapeos de entidades.
- El usuario que genera el codigo es `Owner` del grupo.
- El usuario autorizado pertenece a todas las empresas del grupo.
- Soporte Tecnico esta instalado en la computadora Windows destino.

#### Pasos

1. Iniciar sesion en Administrativo contra la nube con el Owner del grupo.
2. Abrir `Acceso` o la administracion de grupos y seleccionar el grupo correcto.
3. Abrir `Vincular una computadora`.
4. En `Alcance`, seleccionar `Grupo completo`.
5. Seleccionar el usuario autorizado, nombre del equipo y vigencia del codigo.
6. Generar el codigo y copiarlo de forma segura. Es de un solo uso y expira.
7. Abrir `Soporte Tecnico` en Windows.
8. Pegar el codigo, indicar nombre/codigo del equipo, email local y contrasena local.
9. Presionar `Vincular y descargar grupo`.
10. Confirmar que la consola muestra una tarjeta para cada empresa del grupo.
11. Confirmar que cada tarjeta tiene su propio estado de worker y sincronizacion.

La descarga inicial continua en segundo plano. No cerrar Soporte Tecnico durante la primera
preparacion si se esta comprobando el resultado visual; el supervisor local y los workers quedan
administrados por la instalacion compartida.

#### Verificacion segura

No mostrar ni copiar los tokens. Para verificar solamente los slugs configurados, sin imprimir
secretos, ejecutar en PowerShell:

```powershell
$configPath = Join-Path $env:APPDATA "InventarioArens\storage\app\sync-worker\sync-config.json"
$config = Get-Content $configPath -Raw | ConvertFrom-Json
$config.tenants.PSObject.Properties | Select-Object -ExpandProperty Name
```

El resultado debe contener el slug del grupo y el de cada empresa hija. Luego verificar en Soporte
Tecnico que todas las empresas muestran actividad o una descarga inicial pendiente, sin `last_error`.

#### Prueba funcional del grupo

1. En una empresa, crear o modificar un producto de prueba desde Administrativo.
2. Ejecutar `Sync now` para esa empresa y esperar el estado `applied` o equivalente.
3. Repetir la comprobacion en las otras empresas sin cambiar sus tenants manualmente.
4. Confirmar que los eventos de cada empresa llegan solo a su tenant correspondiente.
5. Confirmar que el catalogo y los almacenes se crean aunque los IDs locales sean distintos de los IDs cloud.
6. Confirmar que no aparecen errores de foreign key, `tenant_id`, producto o almacén remoto.
7. Cerrar y volver a abrir Soporte Tecnico; la configuracion debe conservar las cinco empresas.

Si una empresa falla, revisar su tarjeta y `sync.log`; no regenerar todos los codigos ni sustituir
tokens entre empresas. Corregir la empresa afectada y volver a ejecutar su sincronizacion pendiente.

## 7. Prueba de actualizacion y reinicio

1. Crear un producto de prueba y registrar una venta.
2. Cerrar POS y Administrativo normalmente.
3. Reiniciar Windows.
4. Abrir nuevamente ambos clientes.
5. Confirmar que el producto, la venta, la caja y el stock siguen presentes.
6. Instalar manualmente la primera version con actualizador, `0.2.0`, sobre la anterior `0.1.0`.
7. Confirmar que la SQLite anterior no fue reemplazada por una vacia.
8. Publicar una version posterior en el canal correspondiente de GitHub Releases.
9. Abrir el cliente y confirmar que descarga la actualizacion, pregunta antes de reiniciar y conserva los datos.

Nunca aceptar una actualizacion que cambie la ruta de datos sin migracion o backup explicito.

## 8. Logs y diagnostico

Revisar los logs principales:

```powershell
$root = Join-Path $env:APPDATA "InventarioArens"
Get-ChildItem $root -Recurse | Select-Object FullName, Length, LastWriteTime
Get-Content "$root\logs\api.log" -Tail 120
Get-Content "$root\logs\sync.log" -Tail 120
```

Errores frecuentes:

| Sintoma                              | Diagnostico probable                                              | Accion                                                                |
| ------------------------------------ | ----------------------------------------------------------------- | --------------------------------------------------------------------- |
| `VCRUNTIME140_1.dll` faltante        | Falta runtime Visual C++                                          | Instalar Microsoft Visual C++ Redistributable x64                     |
| Pantalla blanca                      | renderer no carga o build incompleto                              | Revisar instalador, ruta de recursos y logs                           |
| `Could not open input file: artisan` | backend no fue incluido o ruta de trabajo incorrecta              | Rehacer staging y build; no copiar solo el frontend                   |
| `No se encontro PHP portable`        | runtime PHP no fue preparado                                      | Ejecutar nuevamente el build con Internet y checksum valido           |
| API no responde en 8787              | PHP/Laravel local no inicio                                       | Revisar `api.log`, permisos de `%APPDATA%` y proceso `php.exe`        |
| `CSRF: Origin not in allowlist`      | Se esta usando un build viejo o API iniciada por un proceso viejo | Cerrar ambos clientes y abrir los dos instaladores nuevos             |
| Actualizacion no aparece             | La version publicada no es mayor o falta metadata del canal       | Revisar version, `latest.yml`/canal y GitHub Release                  |
| Cliente remoto no conecta            | LAN apagado, firewall o host cerrado                              | Revisar Modo LAN, puertos privados y que el host siga abierto         |
| SQLite en `0 bytes`                  | instalacion inicial incompleta                                    | No borrar datos; conservar logs y reparar/reinstalar con backup       |
| POS no ve cambios administrativos    | cache de query o sync pendiente                                   | Recargar cliente, verificar API local y estado de sync                |
| Sync pendiente                       | token, tenant, red o endpoint cloud                               | Revisar configuracion y `sync.log`; no regenerar token de otro tenant |

Para capturar evidencia de una falla, guardar:

- version de Windows;
- version/build de cada instalador;
- fecha y hora;
- pasos exactos para reproducir;
- puertos comprobados;
- tamano y fecha de `inventario.sqlite`;
- `api.log` y `sync.log` relevantes;
- captura de pantalla si hay error visual.

No incluir passwords, tokens Bearer, cookies ni `APP_KEY` en un reporte.

## 9. Seguridad de la prueba

- Los instaladores actuales no estan indicados como firmados digitalmente. Windows SmartScreen puede mostrar una advertencia; verificar hash y origen antes de permitir la ejecucion.
- No desactivar Windows Defender o SmartScreen globalmente.
- No publicar `.env`, tokens, cookies, SQLite de produccion ni logs con secretos.
- No compartir `inventario.sqlite` por una carpeta de red. SQLite local debe vivir en una sola computadora.
- Modo LAN solo debe habilitarse en redes privadas confiables y con firewall limitado a `LocalSubnet`.
- No ejecutar escenarios destructivos contra la nube de produccion. Usar tenant y datos de prueba.
- No usar un token de plataforma para reemplazar el aislamiento de tokens por tenant.

## 10. Criterios de aprobacion

La validacion Windows se considera aprobada solo si:

- los tres instaladores NSIS se construyen sin errores;
- los tres clientes abren en Windows 10/11 x64;
- ambos operan simultaneamente;
- API, renderer y SQLite usan las rutas/puertos esperados;
- login, tenant, catalogo, stock, caja y checkout funcionan;
- `F10` funciona sin error CSRF;
- una venta modifica el stock correcto;
- los datos sobreviven cierre, reinicio y actualizacion;
- sync funciona cuando esta configurado;
- no hay perdida de datos ni secretos expuestos.

## 11. Relacion con el VPS

El POS Electron **no se ejecuta dentro del VPS**. En Windows ejecuta Laravel y SQLite localmente.

El VPS proporciona:

- API cloud Laravel publica;
- PostgreSQL central;
- sincronizacion entre nodos;
- catalogo y datos compartidos segun las reglas del dominio.

Por tanto, desplegar el backend actualizado al VPS no reemplaza el instalador Windows. Se necesitan las dos partes:

1. desplegar el backend Laravel al VPS correcto, `212.28.176.157`, en `/opt/inventarioarens-cloud`;
2. construir/distribuir los nuevos instaladores Electron Windows;
3. configurar el tenant y el token de sync en cada computadora local.

Si el VPS queda temporalmente fuera de servicio, el POS local puede continuar operando con su SQLite local, pero los eventos de sincronizacion quedaran pendientes hasta recuperar la conexion.

Antes de un deploy cloud, confirmar siempre que se trata de INVENTARIOARENS y no de MiInventarioFacil:

```text
Host: 212.28.176.157
Backend: /opt/inventarioarens-cloud/artisan
Base de datos: inventory_arens
Dominio: https://app.miinventariofacil.com/api
```

No tocar contenedores Docker de los otros productos del VPS.

## 12. Relacion con GitHub y AGENTS.md

`AGENTS.md` es un archivo versionado del repositorio. GitHub conserva su contenido exactamente igual cuando se hace commit y push.

Las reglas de tests permanecen en el repositorio, incluyendo:

- tests antes de implementar funcionalidad nueva;
- tests de happy path, error, permisos y cross-tenant;
- tests de sync, stock, dinero y snapshots de tasa cuando corresponda;
- ejecutar tests despues de cada cambio;
- mutation testing cuando este disponible;
- analisis estatico y suite final antes de declarar terminado;
- no eliminar, silenciar ni marcar skipped un test para ocultar una regresion.

Estas reglas son instrucciones para los agentes y colaboradores que lean `AGENTS.md`; GitHub por si solo no las hace cumplir. La proteccion automatica depende de CI, hooks y branch protection.

La validacion Windows de Electron no esta cubierta automaticamente por el job PHP de GitHub Actions. Debe ejecutarse en Windows o agregarse un job Windows que construya NSIS y ejecute las pruebas correspondientes.

## 13. Handoff para IA en Windows

Esta seccion es la guia operativa para una IA que continue el trabajo desde Windows. No debe asumir que
la validacion Linux equivale a validacion Windows: los AppImage ya fueron validados, pero los instaladores
NSIS, PHP portable Windows y el ciclo de vida real de Windows siguen pendientes.

### 13.1 Orden obligatorio

1. Leer este documento completo y `AGENTS.md` antes de modificar codigo.
2. Ejecutar `pnpm test` y `pnpm exec tsc --noEmit` desde `frontend/`.
3. Construir `pnpm run electron:build:admin`, `pnpm run electron:build:pos` y `pnpm run electron:build:technician`.
4. Instalar los tres `.exe` en una PC Windows 10/11 x64.
5. Ejecutar la checklist de las secciones 6 y 7, incluyendo arranque simultaneo, cierre individual, persistencia, reinicio y actualizacion sobre una instalacion existente.
6. Si se valida sync grupal, ejecutar tambien la seccion 6.7.1 y capturar los slugs configurados, nunca los tokens.
7. Capturar logs, hashes, version de Windows y resultado usando el formato de la seccion 14.

### 13.2 Archivos que se pueden modificar

- `frontend/electron/backend-runtime.cjs`: supervisor compartido, leases, heartbeat, locks, Laravel y sync.
- `frontend/electron/main.cjs`: arranque del cliente, modo supervisor, rutas de datos y cierre de Electron.
- `frontend/electron/app-config.cjs`: nombres, IDs, puertos y directorios `userData` por cliente.
- `frontend/electron/backend-runtime.test.js`: tests unitarios del runtime compartido.
- `frontend/electron-builder.admin.yml` y `frontend/electron-builder.pos.yml`: empaquetado NSIS/AppImage.
- `frontend/electron-builder.technician.yml`: empaquetado del cliente tecnico.
- `frontend/electron/auto-updater.cjs` y `frontend/electron/update-policy.cjs`: actualizaciones automaticas por canal.
- `scripts/prepare-portable-php.cjs`: descarga, checksum y staging de PHP portable por plataforma.
- `scripts/stage-electron-backend.cjs`: staging de Laravel, `vendor/` y recursos del backend.
- `scripts/smoke-linux-appimage.cjs`: smoke Linux; no reemplaza la prueba Windows.
- `docs/WINDOWS_ELECTRON_VALIDATION.md`: contrato de validacion y reporte.

### 13.3 Archivos y sistemas que no se deben tocar durante esta validacion

- No cambiar `app/`, migraciones o rutas Laravel para resolver un problema exclusivo de empaquetado Windows
  sin reproducir primero el fallo contra la API local.
- No borrar `%APPDATA%\InventarioArens\inventario.sqlite`; hacer backup antes de cualquier diagnostico.
- No modificar `.harness/`, `.codex/`, `.githooks/` ni workflows CI sin autorizacion explicita.
- No desplegar al VPS ni tocar Docker, Traefik o PostgreSQL para validar los instaladores locales.
- Si se requiere probar sync, usar un tenant y token de prueba de INVENTARIOARENS; no reutilizar credenciales
  de otra empresa ni credenciales del producto MiInventarioFacil.

### 13.4 Contrato del supervisor

- La API local es `127.0.0.1:8787`; Admin usa renderer `8788`, POS usa renderer `8789` y Tecnico usa renderer `8790`.
- Los dos clientes comparten `%APPDATA%\InventarioArens\` para SQLite, storage, logs y leases.
- Cada cliente crea un archivo `.lease` bajo `runtime-leases\` y lo actualiza cada dos segundos.
- El supervisor espera aproximadamente cinco segundos despues del ultimo lease antes de apagar Laravel.
- `.runtime-supervisor.lock` evita dos supervisores; `.runtime-supervisor.pid` sirve para diagnostico.
- Un cierre abrupto puede dejar un lease temporal; el TTL de diez segundos permite recuperacion automatica.
- El supervisor es un proceso Electron desacoplado. No implementar un segundo `php artisan serve` desde
  cada ventana ni eliminar el lease mientras el cliente siga abierto.

### 13.5 Estado de actualizaciones y LAN

- Validacion real de NSIS en Windows o un job Windows de CI.
- Instalacion y operacion del worker de sync como tarea programada/servicio Windows.
- El cliente Tecnico ya esta implementado y reutiliza `/support`.
- `electron-updater` ya esta implementado por los canales `admin`, `pos` y `technician`.
- La version `0.2.0` es el primer bootstrap del actualizador y debe instalarse manualmente.
- El modo LAN es opcional, requiere reinicio de los clientes y firewall privado.
- La vinculacion grupal ya esta implementada: genera tokens independientes y prepara cada tenant
  mediante el cliente Tecnico. La validacion real de una instalacion Windows limpia sigue pendiente.
- Todavia falta una publicacion real en GitHub Releases y la firma digital de los instaladores.
- Todavia falta convertir el runtime LAN en un servicio persistente independiente de las ventanas Electron.

Estos pendientes deben implementarse despues de confirmar que el runtime base, SQLite compartida y el
ciclo de vida Admin/POS funcionan en Windows.

## 14. Reporte final de la IA validadora

La IA debe terminar con este formato:

```text
Resultado: PASS | FAIL | BLOCKED
Windows: <version y arquitectura>
Build Administrativo: PASS | FAIL
Build POS: PASS | FAIL
Build Tecnico: PASS | FAIL
Instalacion Administrativo: PASS | FAIL
Instalacion POS: PASS | FAIL
Instalacion Tecnico: PASS | FAIL
Arranque simultaneo: PASS | FAIL
API local y puertos: PASS | FAIL
Login y tenant: PASS | FAIL
Catalogo e inventario: PASS | FAIL
Checkout POS: PASS | FAIL
F10 sin error CSRF: PASS | FAIL
Persistencia despues de reinicio: PASS | FAIL
Sincronizacion cloud: PASS | FAIL | NOT TESTED
Errores encontrados: <lista>
Evidencia: <rutas de logs, hashes y capturas>
```

No declarar `PASS` si algun punto obligatorio quedo sin ejecutar. Usar `BLOCKED` cuando falte un requisito externo, como credenciales de prueba, acceso al VPS o el runtime de Windows.

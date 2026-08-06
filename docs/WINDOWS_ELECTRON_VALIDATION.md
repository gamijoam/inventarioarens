# Validacion Windows de los clientes Electron

**Proyecto:** INVENTARIOARENS
**Clientes:** `Sistema de Inventario (Administrativo)` y `POS`
**Fecha de referencia:** 2026-08-06
**Objetivo:** guia operativa para que una persona o una IA valide, instale, ejecute y diagnostique los clientes Windows.

## 1. Alcance

Esta guia corresponde a los clientes Electron actuales, empaquetados con NSIS:

- `Sistema de Inventario (Administrativo)`
- `POS`

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

```text
Administrativo renderer  -> http://127.0.0.1:8788
POS renderer             -> http://127.0.0.1:8789
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

Datos persistentes compartidos:

```text
%APPDATA%\InventarioArens\inventario.sqlite
%APPDATA%\InventarioArens\storage\
%APPDATA%\InventarioArens\logs\api.log
%APPDATA%\InventarioArens\logs\sync.log
```

Los datos de configuracion de Electron estan aislados por cliente:

```text
%APPDATA%\InventarioArens-Administrativo\
%APPDATA%\InventarioArens-POS\
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

Construir ambos clientes:

```powershell
pnpm run electron:build:admin
pnpm run electron:build:pos
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
4. Ejecutar primero Administrativo.
5. Esperar hasta que aparezca la pantalla de login.
6. Ejecutar POS.
7. Iniciar sesion en ambos clientes con un usuario y tenant validos.

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

## 7. Prueba de actualizacion y reinicio

1. Crear un producto de prueba y registrar una venta.
2. Cerrar POS y Administrativo normalmente.
3. Reiniciar Windows.
4. Abrir nuevamente ambos clientes.
5. Confirmar que el producto, la venta, la caja y el stock siguen presentes.
6. Instalar una nueva version sobre la anterior.
7. Confirmar que la SQLite anterior no fue reemplazada por una vacia.

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
- No ejecutar escenarios destructivos contra la nube de produccion. Usar tenant y datos de prueba.
- No usar un token de plataforma para reemplazar el aislamiento de tokens por tenant.

## 10. Criterios de aprobacion

La validacion Windows se considera aprobada solo si:

- ambos instaladores NSIS se construyen sin errores;
- ambos clientes abren en Windows 10/11 x64;
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

## 13. Reporte final de la IA validadora

La IA debe terminar con este formato:

```text
Resultado: PASS | FAIL | BLOCKED
Windows: <version y arquitectura>
Build Administrativo: PASS | FAIL
Build POS: PASS | FAIL
Instalacion Administrativo: PASS | FAIL
Instalacion POS: PASS | FAIL
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

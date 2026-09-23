# Motor Local BalanzaPro — Offline, OPcache y servidor multi-worker

> Documento operativo de la intervención del **2026-09-23** sobre la PC del local
> (tenant `asiamoto`) y el repo `/opt/balanzapro-cloud`.
> Cubre: diagnóstico, OPcache, cliente desktop 0.2.64 (offline), concurrencia
> (SQLite vs PostgreSQL) y opciones de servidor.

---

## 1. Síntoma reportado

Tras un corte de luz en el local:

- "El local no quiere abrir"; la app mostraba **"sin conexión"** o **"el motor local no funciona"**.
- Sin internet funcionaba a medias; **al conectar internet "se paralizaba todo"** y había que
  cerrar y volver a abrir la app.

## 2. Diagnóstico

### 2.1 Motor

- El VPS siguió encendido (no lo afectó el corte). En la PC: Motor Local **v0.1.1**.
- Servicios WinSW `SistemaInventarioBackend` (`127.0.0.1:8787`), `SistemaInventarioPrinter`
  (`17777`) y `SistemaInventarioSync`: **running**. `/up` = 200. **El motor estaba sano.**
- Datos en `C:\ProgramData\InventarioArens\inventario.sqlite` (SQLite, WAL, `busy_timeout=15000`).

### 2.2 Sincronización

- `storage/app/sync-worker/sync-config.json`: `{"paused": true, "tenants": {}}`.
- La config real estaba en `sync-config.json.enabled` (empresa `asiamoto`, nodo `LOCAL-01`, token).
- Último intento de sync: `2026-09-21 11:47 UTC`. `outbox_pending=596`, `inbox_failed=4`,
  `inbox_applied=1535`.
- Nube (base `inventory_balanzapro`): `sync_outbox` de `asiamoto` (tenant 4) con
  **11.521 pending**; `sync_inbox` con 4 applied / 7 ignored / **1 failed**.
  - El `failed` es `accounts_receivable.updated` → `SQLSTATE[23505]` unique
    `(tenant_id, sale_id)=(4, 40)` (CxC duplicada de la venta 40).
- `balanzapro-sync.service` del VPS salía `failed` porque `sync:apply-all-inboxes` devuelve
  exit code 1 cuando **algún** tenant tiene eventos fallidos (era el 1 de asiamoto). No es una
  caída total: 2 de 3 tenants aplicaban OK.

### 2.3 Latencia y servidor

- El backend corría `php artisan serve --host=127.0.0.1 --port=8787` → **servidor PHP de un solo
  hilo** (orientado a desarrollo).
- `php_opcache.dll` estaba presente, pero **OPcache no estaba cargado**
  (`zend_extension=opcache` comentado).
- Latencia por request: **~250 ms** (bootstrap) a **~520 ms** (con acceso a DB).

### 2.4 Bug offline del frontend (causa del síntoma principal)

Los clientes Electron son local-first (la API vive en `127.0.0.1:8787`), pero TanStack Query usaba
su `networkMode` por defecto `'online'`:

- **Sin internet**: pausa queries y **mutaciones** (el botón Cobrar quedaba pegado sin resolver).
- **Al reconectar**: `refetchOnReconnect` disparaba **~20 requests simultáneos** (products,
  price-lists, payment-methods, cash-registers, branches, currency, pos/orders, combos, …) contra el
  servidor monohilo → se saturaba y **se congelaba la UI**.

Evidencia en `storage/logs/services/backend/SistemaInventarioBackend.out.log`: ráfaga de ~20
requests en el mismo segundo (p. ej. 20:01:45), con varios ~500 ms por hacer cola.

Antecedentes en git: `f5167951` implementó local-first y `eced8946` lo revirtió por una supuesta
regresión de cobro.

### 2.5 Otros hallazgos

- **Auto-updater roto**: el canal `balanzapro-pos` busca su `balanzapro-pos.yml` dentro del release
  **más reciente** (hoy `v0.2.63-balanzapro-admin`) → **404 permanente** (20 errores en `updater.log`).
  Los releases son por-cliente, así que sólo funciona el del último publicado.
- **LAN**: el servicio arranca con `--host=127.0.0.1` aunque `local-server.json` diga
  `bind_host: 0.0.0.0`; **hoy no acepta conexiones de otras PC**.
- `local-server.json` tenía `restart_required: true` (cambio de modo pendiente).

## 3. Cambios aplicados

### 3.1 OPcache en el Motor (PC del local)

Archivo: `C:\Program Files\Sistema de Inventario\Motor\versions\0.1.1\runtime\php\php.ini`.
Respaldo: `php.ini.bak-20260922-195835`. Bloque agregado:

```ini
; --- opencode: OPcache ---
zend_extension="C:\Program Files\Sistema de Inventario\Motor\versions\0.1.1\runtime\php\ext\php_opcache.dll"
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=192
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.file_cache=C:\ProgramData\InventarioArens\opcache
opcache.file_cache_only=0
```

`opcache.enable_cli=1` es **imprescindible**: el server embebido usa SAPI CLI. Luego se reinició
`SistemaInventarioBackend`.

Medición (`Invoke-WebRequest`, 6-8 muestras):

| Endpoint | Antes | Después |
|---|---|---|
| `/up` (bootstrap puro) | ~250 ms | **~50 ms** |
| `/api/local-support/status` (DB + `sc query`) | ~510 ms | **~285 ms** |
| Endpoints POS típicos (ya en caliente) | ~500 ms | **~1-2 ms** |

### 3.2 Cliente desktop 0.2.64 (offline + anti-burst)

Rama `fix/local-first-offline`, commit `87bc2b6a`.

- `frontend/src/lib/queryClient.ts`: para backend loopback (Electron) →
  `networkMode: 'always'` en **queries y mutaciones** (no pausa el cobro sin internet) y
  `refetchOnReconnect: false` (elimina el burst al volver internet). Se conserva
  `refetchOnWindowFocus` para no alterar el flujo de cobro. La **web de nube** conserva el modo
  `online` estándar.
- `frontend/src/lib/queryClient.test.ts` actualizado; `frontend/package.json` → `0.2.64`.
- Validación: `vitest run` → **175 archivos / 1019 tests OK**; `tsc --noEmit` limpio.
- Build: `pnpm exec electron-builder --config electron-builder.balanzapro-{pos,admin,technician}.yml
  --win --dir` (con `--dir` no hace falta wine; `app.asar` ~105 MB).
- Deploy: se reemplazó `resources\app.asar` en
  `C:\Program Files\BalanzaPro-{POS,Administrativo,Soporte-Tecnico}`. La validación de integridad
  de asar está **deshabilitada** (`EnableEmbeddedAsarIntegrityValidation: Disabled`), por eso alcanza
  con el asar.
- Respaldos: `app.asar.bak-20260922-202734` en cada carpeta.
- **Rollback**: cerrar la app y renombrar el `.bak-...` por encima de `app.asar`.

### 3.3 Sincronización

Se dejó **desactivada** (decisión del cliente: no usa la nube). No se restauró la config ni se
forzó un sync. El backlog local (596) y de nube (11.521) queda pendiente para el futuro.

## 4. Guía: concurrencia y SQLite vs PostgreSQL

- SQLite en **WAL**: múltiples lectores concurrentes y **un escritor a la vez**. Una venta es una
  escritura **corta** (milisegundos). En la práctica **4 cajas pueden vender simultáneamente**; si
  dos escrituras coinciden, la segunda espera (`busy_timeout=15000`).
- **El cuello real es el servidor monohilo**, no SQLite: N cajas generan N requests que hacen cola.
  Con OPcache mejoró mucho; para ir sobrado falta el servidor multi-worker.
- Reglas para que rinda: **SSD**, WAL (ok), `busy_timeout` (ok), **sin transacciones largas**
  (el sync en lote era el peor: por eso desactivarlo ayuda), y **backup diario** del `.sqlite`.
- **PostgreSQL** recién se justifica con muchas cajas (≥6-8) o escritura concurrente pesada
  (imports/reportes masivos). No para 4.

## 5. Servidor multi-worker (recomendación)

### 5.1 El problema

`php artisan serve` atiende **1 request a la vez**. En Windows `PHP_CLI_SERVER_WORKERS` se ignora
(por eso no sirvió el intento de "8 workers").

### 5.2 Opciones

1. **RoadRunner** (`spiral/roadrunner-laravel`): binario Go, mantiene **N workers PHP** y atiende en
   paralelo. Soporte Windows sólido. **Recomendado.**
2. **FrankenPHP**: binario único con *worker mode*; verificar madurez de Windows antes de producción.
3. **nginx + php-cgi/FastCGI**: clásico, pero PHP-FPM oficial no existe para Windows; no recomendado.
4. **Quedarse con `artisan serve` + OPcache**: aceptable para 1-2 cajas; no para 4.

### 5.3 Arquitectura objetivo

```
Electron POS/Admin (N cajas)
        │  HTTP  (127.0.0.1, o LAN :8787)
        ▼
RoadRunner (N workers PHP)  ── con OPcache
        │
        ▼
SQLite inventario.sqlite  (WAL, 1 escritor)
```

Pasos: agregar el paquete, configurar `rr serve` con N workers (p. ej. 4-8), cambiar el `executable`
y `arguments` del servicio WinSW `SistemaInventarioBackend`, mantener SQLite y abrir firewall si es
LAN.

## 6. Pendientes / próximos pasos

1. **LAN para las otras PC**: backend con `--host=0.0.0.0`, firewall puerto **8787**, apuntar los
   clientes a la IP de la PC servidora.
2. **Servidor multi-worker (RoadRunner)** para concurrencia real de 4 cajas.
3. **Arreglar el auto-updater**: publicar **un release único** con los 3 `.yml` (o usar
   `provider: generic`), porque hoy los canales POS/Soporte reciben 404.
4. **Baked-in**: incluir OPcache en el instalador/scripts del Motor para que no se pierda al
   actualizar.
5. **Backup diario del SQLite** (`VACUUM INTO` a la carpeta `backups`).
6. **Revisar el evento fallido de CxC** (venta `sale_id=40`) en la nube.

## 7. Anexos

- Respaldos: `php.ini.bak-20260922-195835`, `app.asar.bak-20260922-202734`.
- Identificadores: tenant `asiamoto` (id 4), nodo `LOCAL-01`, `installation_code HVAAWYM6KGTP`
  (sin exponer el token).
- Medición OPcache:
  `Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8787/up` con `Diagnostics.Stopwatch`.
- Verificar OPcache:
  `& "...\runtime\php\php.exe" -r "var_dump(function_exists('opcache_get_status'));"` → `true`.


## 8. FrankenPHP implementado (servidor multi-hilo) — 2026-09-23

Se reemplazó `php artisan serve` (monohilo) por **FrankenPHP** como servidor del
Motor Local, sin tocar el código de la aplicación ni la base de datos.

### 8.1 Por qué FrankenPHP

- Un **solo binario** (`frankenphp.exe`) con Caddy + PHP embebido (ZTS,
  multi-hilo). Corre Laravel sin paquetes nuevos (a diferencia de RoadRunner,
  cuyo bridge puede no soportar Laravel 13).
- Usa **PHP 8.5.10** embebido con las extensiones que necesita la app.
- Se probó en el VPS (Linux) y en la PC (Windows): **atiende requests en paralelo**.

### 8.2 Qué se hizo

1. Se descargó `frankenphp-windows-x86_64.zip` (v1.12.7) y se extrajo.
2. Se copió a `C:\ProgramData\InventarioArens\runtime\frankenphp\` (carpeta de
   datos, no se borra al actualizar el Motor).
3. Se creó `php.ini` con `extension_dir` absoluto y extensiones:
   `pdo_sqlite, sqlite3, mbstring, fileinfo, openssl, curl, zip, gd, intl, sodium`
   (+ OPcache). Sin este ini, el PHP embebido arranca **sin extensiones**.
4. Se respaldó y reescribió el servicio WinSW
   `...\service\SistemaInventarioBackend.xml`:
   - `executable` → `C:\ProgramData\InventarioArens\runtime\frankenphp\frankenphp.exe`
   - `arguments` → `php-server --root "...\backend\public" --listen 127.0.0.1:8787`
   - `<env name="PHPRC" value="...\frankenphp" />` (para que cargue el php.ini)
   - Se conservó **todo** el bloque `<env>` original (DB, storage, app key, etc.).
5. Se reinició el servicio.

Respaldo del XML original:
`SistemaInventarioBackend.xml.bak-20260922-204106`
(rollback: restaurarlo y reiniciar `SistemaInventarioBackend`).

### 8.3 Resultados

- `/up` = 200; API con SQLite = 200.
- FrankenPHP arranca con `num_threads: 8, max_threads: 8`.
- **Concurrencia medida** (`/api/local-support/status`):
  - 6 requests **secuenciales**: ~1754 ms (~292 ms c/u)
  - 6 requests **concurrentes**: **~469 ms** → **en paralelo** ✅
- Servicios `SistemaInventarioBackend` (FrankenPHP), `Printer` y `Sync`: running.

### 8.4 Script reproducible

Se agregó `scripts/local-motor-frankenphp.ps1` que automatiza todo lo anterior
(copia, `php.ini`, backup + edición del XML, reinicio). Requiere administrador y
la carpeta de FrankenPHP ya extraída.

### 8.5 Consideraciones

- **El cambio se pierde si se reinstala/actualiza el Motor**: el instalador
  regenera el XML del servicio. Hay que volver a correr
  `scripts/local-motor-frankenphp.ps1` (o, mejor, bundlear FrankenPHP en el
  release del Motor).
- Para **baked-in** hay que incluir el binario en el payload del Motor
  (`scripts/build-local-motor.ps1` / `install-local-motor.ps1` / `.iss`) y en el
  workflow `release-motor.yml`.
- SQLite sigue igual (WAL, 1 escritor). Concurrencia de escritura corta → sin
  cambios; `busy_timeout=15000` protege los picos.
- Para **varias PC** falta además: bind `0.0.0.0` en `frankenphp ... --listen`
  + firewall puerto 8787.

## 9. Baked-in de FrankenPHP en el Motor (CI)

Para que el próximo instalador del Motor ya incluya FrankenPHP (y no haya que
correr `local-motor-frankenphp.ps1` a mano) se modificó:

- `scripts/prepare-frankenphp.cjs` (nuevo): descarga y verifica (SHA-256) el zip
  `frankenphp-windows-x86_64.zip` v1.12.7 y lo extrae en
  `build/windows-runtime/frankenphp`.
- `scripts/build-local-motor.ps1`: llama a `prepare-frankenphp.cjs` (omitible con
  `-SkipFrankenPhp`).
- `scripts/stage-local-motor.cjs`: copia FrankenPHP al payload como
  `runtime/frankenphp` si está presente.
- `scripts/install-local-motor.ps1`: si el payload trae `runtime/frankenphp`,
  escribe su `php.ini` y usa FrankenPHP para el servicio backend
  (`php-server --root ".../public" --listen 127.0.0.1:8787` + env `PHPRC`);
  si no, cae a `php artisan serve` (compatibilidad).

Falta ejecutar el workflow `release-motor.yml` (windows-latest) para generar el
`.exe` del Motor con FrankenPHP incluido.

## 10. Instalador del Motor "todo incluido" (validado 2026-09-23)

Se generó y probó un instalador del Motor que en una PC **desde cero** deja todo
optimizado sin pasos manuales.

- Archivo: `Motor-Local-Sistema-Inventario-0.1.2.exe` (~67 MB, Inno Setup).
- Contenido del payload: backend Laravel + PHP portable (`php.ini` con **OPcache**)
  + **FrankenPHP** (multi-hilo) + WinSW + `install-local-motor.ps1` parchado.
- `install-local-motor.ps1` usa **FrankenPHP** automáticamente para el backend si
  el payload lo trae (le escribe su `php.ini` propio y define `PHPRC`); Printer y
  Sync siguen usando el PHP portable (también con OPcache).

Prueba real (misma PC, instalación silenciosa `--VERYSILENT`):

- `installer exit=0`; servicios Backend / Printer / Sync **Running**.
- `SistemaInventarioBackend.xml` → `frankenphp.exe php-server --root
  "...\versions\0.1.2\backend\public" --listen 127.0.0.1:8787` + `env PHPRC`.
- `runtime\php\php.ini` con `zend_extension=opcache` y `opcache.enable_cli=1`.
- `runtime\frankenphp\php.ini` con `extension_dir` absoluto a la versión instalada.
- `/up` ~50 ms; `function_exists('opcache_get_status')` = true.
- `motor-install.log`: "Motor backend: FrankenPHP (multi-hilo). Motor Local 0.1.2
  instalado. La base de datos y los tokens existentes se conservaron."

Notas:

- El `.exe` **no está firmado** → Windows SmartScreen puede advertir (Más
  información → Ejecutar de todas formas).
- Se construyó **nativamente en Windows con Inno Setup 6.7.3**; el CI
  `release-motor.yml` hace lo mismo en `windows-latest` a partir de los scripts
  del repo (`prepare-portable-php.cjs`, `prepare-frankenphp.cjs`,
  `stage-local-motor.cjs`, `install-local-motor.ps1`).
- Pendiente: publicar el instalador como release de GitHub (requiere token) para
  que sea descargable por todos y para el auto-update.

### Experiencia final del técnico (PC desde cero)

1. `Motor-Local-Sistema-Inventario-0.1.2.exe` → motor con FrankenPHP + OPcache.
2. Cliente `BalanzaPro-{POS,Administrativo,Soporte-Tecnico}-0.2.64.exe`.
3. (Si son varias cajas) habilitar LAN en el Motor + apuntar los clientes.

## 11. Publicación en GitHub (2026-09-23)

Se publicó todo en `gamijoam/inventarioarens`:

- **Push de `main`**: `a7f8880c..bf51587f` (fast-forward). Quedan en GitHub los
  commits de esta intervención (`87bc2b6a` fix offline 0.2.64, `940c172b`
  FrankenPHP, `5953a2a2` OPcache, docs).
- **Release del Motor** `motor-v0.1.2-balanzapro`:
  `Motor-Local-Sistema-Inventario-0.1.2.exe` (+ `.sha256`).
- **Release de clientes** `v0.2.64-balanzapro` (es el **latest**): incluye los 3
  clientes juntos (`BalanzaPro-{POS,Administrativo,Soporte-Tecnico}-0.2.64.exe`,
  sus `.blockmap` y sus `*.yml`).

### Descargas

- Motor:
  `https://github.com/gamijoam/inventarioarens/releases/download/motor-v0.1.2-balanzapro/Motor-Local-Sistema-Inventario-0.1.2.exe`
- POS:
  `https://github.com/gamijoam/inventarioarens/releases/download/v0.2.64-balanzapro/BalanzaPro-POS-0.2.64.exe`
- Administrativo:
  `https://github.com/gamijoam/inventarioarens/releases/download/v0.2.64-balanzapro/BalanzaPro-Administrativo-0.2.64.exe`
- Soporte:
  `https://github.com/gamijoam/inventarioarens/releases/download/v0.2.64-balanzapro/BalanzaPro-Soporte-Tecnico-0.2.64.exe`

### Auto-update

- El proveedor GitHub de `electron-updater` toma el **release más reciente** y
  busca allí `<channel>.yml`. Como el latest (`v0.2.64-balanzapro`) contiene los
  **tres** `.yml`, los tres clientes actualizan de 0.2.63 → 0.2.64.
- ⚠️ **Regla**: no publicar otro release *después* del de clientes, o el nuevo
  release queda como "latest" y los clientes vuelven a recibir 404 de su `.yml`.
  Si se republica el Motor, volver a "refrescar" el release de clientes después.
  Mejor solución a futuro: migrar el updater a `provider: generic` apuntando a una
  URL propia (p. ej. `https://app.balanzapro.com/downloads/<channel>/`).

### Notas

- La subida de assets se hace contra `https://uploads.github.com/...` (con
  `api.github.com` devuelve 404).
- El token usado se recomienda **revocarlo/regenerarlo** por haber circulado.

## 12. Configuracion inicial offline + clientes pantalla completa (0.2.65)

### 12.1 Script "Configurar BalanzaPro" (primer usuario offline)

`scripts/Configurar-BalanzaPro.ps1` (+ `.cmd` para doble clic) crea el primer
administrador y la primera empresa en una instalacion **nueva**, sin nube y sin
escribir URLs:

1. Verifica que el Motor responda en `127.0.0.1:8787`.
2. Consulta `GET /api/bootstrap/status`.
3. Si la base **ya tiene** usuarios/empresas → avisa y sale (no pide admin).
4. Si esta vacia: pide datos (nombre, email, contrasena, empresa) y hace
   `POST /api/bootstrap` con el token leido de
   `C:\ProgramData\InventarioArens\bootstrap.token` (requiere admin, por eso
   el `.ps1` se auto-eleva).
5. Muestra el resultado y recuerda iniciar sesion en el cliente.

El token vive en `C:\ProgramData\InventarioArens\bootstrap.token` (ACL: solo
SYSTEM y Administradores). `bootstrap/app.php` lo mapea a `APP_BOOTSTRAP_TOKEN`.
El endpoint solo funciona con la base vacia (defensa en profundidad).

### 12.2 Clientes a pantalla completa sin bordes

`frontend/electron/main.cjs`: la ventana abre con `fullscreen: true` y el menu
oculto (sin bordes, como F11 del navegador).

Salidas:

- **F11** alterna pantalla completa.
- **Esc** sale de pantalla completa (queda en ventana).
- **Ctrl+Shift+Q** cierra la aplicacion.
- El POS conserva su boton "Salir del POS".

### 12.3 Releases publicados

- `motor-v0.1.2-balanzapro` → `Motor-Local-Sistema-Inventario-0.1.2.exe` (FrankenPHP + OPcache).
- `v0.2.65-balanzapro` (**latest**) → clientes POS/Admin/Soporte 0.2.65 (pantalla completa) con `.blockmap` y `.yml`.

Descargas:

```
https://github.com/gamijoam/inventarioarens/releases/download/v0.2.65-balanzapro/BalanzaPro-POS-0.2.65.exe
https://github.com/gamijoam/inventarioarens/releases/download/v0.2.65-balanzapro/BalanzaPro-Administrativo-0.2.65.exe
https://github.com/gamijoam/inventarioarens/releases/download/v0.2.65-balanzapro/BalanzaPro-Soporte-Tecnico-0.2.65.exe
https://github.com/gamijoam/inventarioarens/releases/download/motor-v0.1.2-balanzapro/Motor-Local-Sistema-Inventario-0.1.2.exe
```

Recordatorio: el release **latest** debe seguir conteniendo los 3 `.yml` para que
el auto-update funcione (ver seccion 11).

## 13. Fix POS: cancelar orden pendiente idempotente (Motor 0.1.3)

**Sintoma**: una orden POS podia quedar **huerfana en `open`** si su venta ya
estaba `cancelled`. El backend exige que la venta este en `draft` para cancelar
o cobrar, asi que la orden quedaba trabada para siempre (ni cancelar ni cobrar).

**Fix** (`PosCheckoutService::cancelPending`, commit `4c27bd0d`): si la venta ya
esta `cancelled`, la orden se cierra igual (libera la reserva de stock, marca
`cancelled` y registra el evento). Test:
`test_pending_pos_order_whose_sale_is_already_cancelled_can_be_closed`.
Suite POS: 101 tests OK.

**Reparacion puntual**: en la PC del local se corrigio la orden **#165**
(estaba `open` con venta `cancelled` y un pago capturado). Se hizo backup
(`backups/inventario-before-fix165-*.sqlite`) y se marco la orden `cancelled`.

**Release**: `motor-v0.1.3-balanzapro` → `Motor-Local-Sistema-Inventario-0.1.3.exe`
(se publica como **pre-release** para no desplazar el `latest` de los clientes;
el Motor no tiene auto-update). Commit del hash de FrankenPHP: `0f5e2711`
(el asset upstream fue republicado; se actualizo el SHA-256).

Nota: los releases del **Motor** se mantienen como *pre-release* a proposito.

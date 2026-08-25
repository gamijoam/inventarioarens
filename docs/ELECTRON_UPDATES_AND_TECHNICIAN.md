# Electron Updates and Technician Client

> Actualizacion 2026-08-25: la infraestructura local canonica es el instalador independiente
> **Motor Local - Sistema de Inventario** descrito en
> `docs/MOTOR_LOCAL_WINDOWS_PLAN_2026-08-14.md`. Administrativo, POS y Soporte Técnico son clientes
> UI-only: no contienen PHP/Laravel y no crean, reparan ni eliminan servicios o tareas. Las secciones
> históricas de este documento que describen backend o tareas dentro de Electron no deben aplicarse
> a releases nuevos.

## Current Clients

The repository builds three Windows/Linux Electron clients:

| Client | Mode | Update channel | Main function |
| --- | --- | --- | --- |
| Administrative | `admin` | `admin` | Business administration |
| POS | `pos` | `pos` | Point of sale |
| Technical Support | `technician` | `technician` | Local installation and sync support |

Each client includes only its Electron shell and React UI. Laravel, PHP, SQLite, printing and sync are
owned by the independent Motor Local installer. Persistent data is stored outside the client
installation directories, so replacing an application does not replace the local database, sync
tokens, logs or storage.

Each client installs into its own per-user folder (`oneClick: false` + `executableName`). This stops
the Administrative/POS/Technician installers from landing in the same `inventarioarens-frontend`
folder and overwriting each other's `app.asar`.

The three `electron-builder.<client>.yml` files also include only the matching `dist/<client>` renderer
bundle. The Electron shell remains shared, but an installer does not carry the UI bundle or routes of
another client. `frontend/electron/client-bundles.test.js` protects this packaging contract.

## Automatic Updates

`electron-updater` is configured with GitHub Releases. Each client uses its own channel
(`admin` / `pos` / `technician`) so artifacts do not overwrite each other's update metadata. The
update metadata file is `<channel>.yml` (e.g. `pos.yml`), not `latest.yml`.

The updater:

- is disabled in development and in the detached runtime supervisor;
- checks after startup in packaged builds and then every **1 minute** (constant
  `UPDATE_CHECK_INTERVAL_MS` in `frontend/electron/auto-updater.cjs`), without overlapping a
  previous check;
- downloads a new version in the background;
- shows a notice when a newer version is detected and a second prompt when the download finishes;
- asks whether to restart immediately after the download;
- installs automatically on the next application exit if the user chooses to continue working.

Updater diagnostics are persisted in the per-client Electron data directory:
`%APPDATA%\InventarioArens-Administrativo\updater.log`,
`%APPDATA%\InventarioArens-POS\updater.log` or
`%APPDATA%\InventarioArens-Soporte\updater.log`.

The update does not stop the VPS and does not modify the VPS backend. It replaces only the desktop
application and its bundled local runtime. The local runtime runs migrations when it starts.

## Release Workflow (GitHub Actions)

`.github/workflows/release.yml` builds and publishes a client automatically. Trigger it with:

```bash
gh workflow run release.yml -f client=pos        # or admin / technician
```

The workflow:

1. checks out, installs composer deps and frontend deps on `windows-latest`;
2. runs `pnpm run build:<client>` (tsc + vite build), then `electron-builder` **without** `--publish`
   (electron-builder + GITHUB_TOKEN leaves drafts and can
   drop the large installer);
3. publishes explicitly with `gh release create v<version>-<client>` (non-draft) and uploads the
   `.exe`, `.blockmap` and `<channel>.yml`.

Note the tag must be `v<version>-<client>` (e.g. `v0.2.3-pos`). When the workflow runs from a tag,
it derives the client from this suffix; a tag without `-admin`, `-pos` or `-technician` is rejected.
The three clients can have the same
`package.json` version without colliding on the tag.

### Important build rules

- The published installer must be produced from a current build. `pnpm run build:<client>` regenerates
  `dist/<client>`; then `electron-builder` packages it. If you run `electron-builder` alone without
  regenerating `dist`, it packages a stale bundle and the released app does not include the latest UI
  change.
- If the GitHub release already exists, the workflow deletes it (`gh release delete --cleanup-tag`)
  and recreates it, so a re-publish replaces the old installer.

### Manual publish fallback

If you need to publish from a local machine (faster, avoids CI empaquetado bugs):

```bash
cd frontend
pnpm run build:pos
pnpm exec electron-builder --config electron-builder.pos.yml
cd release/pos
gh release create v<version>-pos --repo gamijoam/inventarioarens --title "<version>" \
  Sistema-de-Inventario-POS-<version>.exe Sistema-de-Inventario-POS-<version>.exe.blockmap pos.yml
```

## Version Bumping

For every release bump the version in `frontend/package.json` (semver). electron-updater only
updates when the published version is higher than the installed one. Re-publishing the same version
will not reinstall on already-updated machines.

## First Update Bootstrap

Installers distributed before `electron-updater` was added do not contain the updater. They require
one manual installation of a release that contains the updater. After that bootstrap release,
subsequent versions are downloaded and installed automatically.

## Technical Support Client and Background Sync Workers

### Workers are Windows Scheduled Tasks (app-independent)

Background sync runs through one Windows Scheduled Task per company, created by the technician when a
group is linked. Each task runs every **1 minute** and executes `sync-worker.cmd run -TenantSlug
<slug>`, which calls `php artisan sync:run` (one push+pull+apply cycle). This is independent of the
Electron app: sync keeps working with the technician/POS/admin closed.

Key pieces:

- `LocalTechnicalConsoleService::installWindowsWorkerTaskQuiet` installs the task during group link.
- `workerLauncherContent` writes a `.cmd` that sets `LARAVEL_STORAGE_PATH`, `DB_DATABASE` (the data
  root sqlite) and `PHP_INI_SCAN_DIR`, then runs `sync-worker.cmd run ...` with `-PhpPath`.
- `workerTaskCommand` uses **short (8.3) paths** so the `schtasks /TR` command stays under the
  261-character limit (long per-client install folders previously exceeded it).
- The Electron supervisor no longer spawns sync daemons; sync lives entirely in the scheduled task.

The technician UI no longer shows start/stop/restart worker buttons; it shows a badge that background
sync is active while the app is open. Reinstalling the client does not remove the scheduled tasks
(they are per-user Windows tasks pointing at the installed path).

### Sync direction

- **Local -> Cloud**: the push sends `product.*`, `exchange_rate.*`, etc. `applyProduct` matches the
  target row by `sku` (or `catalog_product_id` when present), not by "first product of tenant".
- **Cloud -> Children**: the shared catalog propagation (`SharedCatalogPropagationService`) emits a
  `sync_outbox` event under each child tenant after copying products, exchange rates, brands, tags,
  price lists, exchange rate types, payment methods, warranties and suppliers, so each child's local
  node pulls the change.
- A background daemon on the VPS (`inventarioarens-sync.timer`, every 15s) applies pending inboxes
  for all tenants; ACKs are sent concurrently (`Http::pool`) to avoid per-cycle timeouts.

### Known operational notes

- `sync:prepare-local` replicates `is_group` and `parent_id` from the remote group so the local
  tenant hierarchy mirrors the cloud (group root vs spinoffs).
- If a child snapshot is incomplete because thousands of duplicate `initial-snapshot` events pile up,
  cleaning the redundant pending snapshot events in the VPS outbox lets the worker reach the real
  changes immediately.
- Scheduled tasks keep the DB open; manual SQLite writes may hit `database is locked` while a worker
  runs. Stop the tasks temporarily before large local DB fixes.

## CI

`.github/workflows/ci.yml` runs only the frontend job (tsc + vitest). The PHPUnit Feature suite is
validated locally with `phpunit.sqlite.xml`; running it in CI with PostgreSQL took 15+ minutes and
had pre-existing failures (180s `set_time_limit`, heavy demo seeders), so it was removed from CI.
The demo seeder tests are tagged `@group heavy`.

## Runbook: publicar un fix de cliente (PASO A PASO)

Este es el flujo completo para lanzar un cambio (bugfix o feature) a los clientes de escritorio.
Estos pasos aplican a cambios de interfaz. Los cambios de Laravel, PHP, impresion o sync se publican
por separado mediante el release del Motor Local.

### Antes de empezar (checklist)

- [ ] Los cambios backend corren al menos los tests del modulo afectado: `php vendor/bin/phpunit tests/Feature/<Modulo>/`
- [ ] Los cambios frontend pasan typecheck y tests: `cd frontend && pnpm typecheck && pnpm test`
- [ ] El fix esta commiteado y pusheado a `origin/main` (el workflow publica desde el repo, no desde tu PC)

### Paso 1 - Subir version en frontend/package.json

electron-updater SOLO actualiza cuando la version publicada es **mayor** que la instalada. Si no
subes la version, re-publicar lo mismo NO reinstala nada en las maquinas ya actualizadas.

```bash
# En frontend/package.json sube el campo "version" (semver), e.g. 0.2.3 -> 0.2.4
git add frontend/package.json
git commit -m "chore: bump version to 0.2.4"
git push
```

### Paso 2 - Publicar el/los cliente(s)

Un release por cliente, tag `v<version>-<client>`:

```bash
gh workflow run release.yml -f client=pos          --repo gamijoam/inventarioarens
gh workflow run release.yml -f client=admin        --repo gamijoam/inventarioarens
gh workflow run release.yml -f client=technician   --repo gamijoam/inventarioarens
```

Seguir el estado hasta que termine (3-4 min):

```bash
gh run watch --repo gamijoam/inventarioarens
```

Regla de oro: si una PC tiene los tres clientes instalados y comparten el SQLite local y el puerto
`127.0.0.1:8787`, **publica los tres a la misma version** para evitar que un backend viejo sirva
contra una DB ya migrada por otro cliente nuevo.

### Paso 3 - Verificar la publicacion

```bash
# Debe salir no-draft y con 3 assets (.exe, .blockmap, <channel>.yml)
gh release view v0.2.4-pos --repo gamijoam/inventarioarens --json tagName,isDraft,assets
```

### Paso 4 - En la PC del usuario

El cliente chequea actualizaciones al abrir y cada 1 minuto, descarga en background y pregunta si
reiniciar. Si el usuario elige seguir trabajando, instala al cerrar la app.

### Errores comunes y que hacer

| Sintoma | Causa probable | Fix |
| --- | --- | --- |
| El release publica la UI vieja / el fix "no llego" | `electron-builder` empaqueto sin regenerar `dist/<client>` | El workflow SIEMPRE corre `pnpm run build:<client>` antes de empaquetar. Si publicaste desde tu PC manualmente, corre `pnpm run build:<client>` antes de `electron-builder`. |
| Release queda en Draft o sin `.exe` | electron-builder + GITHUB_TOKEN deja drafts y puede soltar el instalador grande | El workflow publica explícito con `gh release create` (no-draft). Si hiciste publish manual y quedo draft, repite el fallback manual con `gh release create`. |
| La app no muestra ningún aviso | Falló la comprobación o la descarga y solo se revisó la pantalla | Revisa el `updater.log` del cliente; ahora registra el canal, la versión, el progreso y el error exacto. |
| La maquina no descarga la nueva version | Version no bumpiada o canal equivocado | El updater solo aplica versiones MAYORES. Verifica que subiste `package.json` y que el tag es `v<version>-<client>`. |
| Los tres clientes quedan en la misma carpeta y se pisan el `app.asar` | Installers antiguos compartiendo `%LOCALAPPDATA%\Programs\inventarioarens-frontend\` | Reinstalar con los builds nuevos (`oneClick: false` + `executableName` por cliente). Ver workaround en AGENTS.md §14. |
| Sync no refleja un fix de datos | El worker es una tarea programada (cada 1 min), no depende de la app | No requiere actualizar la app; espera el ciclo del worker o corre `php artisan sync:run <slug>` local. |
| Backend nube debe actualizarse | El fix toca el backend del VPS | `ssh root@212.28.176.157` + `git pull` + `composer install --no-dev --optimize-autoloader` + `php artisan optimize:clear` + `php artisan migrate --force`. Independiente de los clientes de escritorio. |

### Estado publicado (2026-08-09)

| Cliente | Version | Tag | Assets |
| --- | --- | --- | --- |
| POS | 0.2.18 | `v0.2.18-pos` | `Sistema-de-Inventario-POS-0.2.18.exe` + blockmap + `pos.yml` |
| Admin | 0.2.5 | `v0.2.5-admin` | `Sistema-de-Inventario-Administrativo-0.2.5.exe` + blockmap + `admin.yml` |
| Technician | 0.2.5 | `v0.2.5-technician` | `Soporte-Tecnico-Inventario-0.2.5.exe` + blockmap + `technician.yml` |

Historial POS reciente (mismo `frontend/package.json`, tags `v0.2.x-pos`):

- `0.2.4` fix LAN renderer (URL loopback con bind 0.0.0.0)
- `0.2.5` alineacion de los tres clientes + fix LAN compartido
- `0.2.6` soporte tactil basico (onPointerDown + touch-action)
- `0.2.7` tap en busqueda con teclado abierto (preventDefault en pointerdown)
- `0.2.8` tap tactil global (installPosTouchTap)
- `0.2.9` blur input + no autofocus en tactil (installPosTouchTap retirado)
- `0.2.10` touchTapHandlers (onTouchStart/End nativos)
- `0.2.11` cierre de teclado en touchstart (Android touchcancel)
- `0.2.12` same + dedup
- `0.2.13` libreria @use-gesture/react (TapButton + usePosTap)
- `0.2.14` auditoria: disparar en pointerdown (cubre pointercancel de Android)
- `0.2.15` nueva pantalla tactil "Armar orden" `/pos/armar` (teclado on-screen propio, redirige vendedores con `pos.orders.hold` sin `pos.checkout`)
- `0.2.16` diagnostico temporal para confirmar si el tap llegaba al producto en tablet
- `0.2.17` pantalla `/pos/armar` sin sidebar administrativo y productos con control tactil robusto
- `0.2.18` `/pos/armar` se monta como pantalla independiente; corrige el terminal clasico que seguia visible y no agregaba productos al tocar

## LAN Server Mode

The local API remains bound to `127.0.0.1` by default. The Technical Support client can enable an
explicit LAN server mode. The setting is written to the shared data root and requires restarting the
Electron clients. The host client must remain open while remote clients use the installation.

When LAN mode is enabled, the host exposes these renderer URLs on the private network:

```text
http://HOST-IP:8788  Administrative
http://HOST-IP:8789  POS
http://HOST-IP:8790  Technical Support (normally keep this local)
```

The renderers proxy API requests to the local Laravel process. The technical console endpoints remain
available only through loopback. Regular API requests still require normal user authentication and
tenant headers.

Windows Firewall should be opened only on the Private profile and only for the local subnet. Example
PowerShell command, run as Administrator on the host:

```powershell
New-NetFirewallRule `
  -DisplayName "Inventario Arens LAN" `
  -Direction Inbound `
  -Action Allow `
  -Protocol TCP `
  -LocalPort 8787,8788,8789 `
  -Profile Private `
  -RemoteAddress LocalSubnet
```

Remove the rule to disable LAN access:

```powershell
Remove-NetFirewallRule -DisplayName "Inventario Arens LAN"
```

Do not share `inventario.sqlite` through SMB and do not expose PostgreSQL. Remote clients must use the
HTTP API/renderer, never the database file.

### Probar el flujo "vendedor arma -> cajera cobra" desde la tablet (LAN)

Requisitos previos en la PC host:

- Clientes actualizados (al menos POS `>= 0.2.4`, que trae `pos.orders.hold` y el modo vendedor).
- El permiso `pos.orders.hold` debe existir localmente: si la PC ya estaba preparada antes del
  release, re-correr `php artisan sync:prepare-local <slug> <empresa> <email>` (idempotente) o
  re-vincular desde el Soporte Técnico. Recuerda: roles/permisos NO viajan por sync.
- Dos usuarios locales con permisos distintos (si quieres separar los roles):
  - **Vendedor** (solo arma): rol con `pos.view` + `pos.orders.hold` (SIN `pos.checkout`).
  - **Cajera** (solo cobra): rol con `pos.view` + `pos.checkout` + `pos.cancel` + `cash_register.open`.
  - Si un usuario tiene ambos permisos, el POS le muestra el flujo completo de cajera (no el modo vendedor).

Pasos:

1. **Activar LAN en el host**: Soporte Técnico -> opción de conexión por red -> activar. Esto escribe
   `bind_host: 0.0.0.0` en `%APPDATA%\InventarioArens\local-server.json`. **Reiniciar los clientes**
   del host para que el cambio tome efecto (el supervisor de la API local respeta el bind host al arrancar).
2. **Abrir el firewall privado** (ver regla arriba, puertos `8787,8788,8789`, solo subnet local).
3. **Conocer la IP del host**: `ipconfig` (adaptador de red privada). La tablet debe estar en la MISMA red.
4. **Desde la tablet** (navegador) abrir:
   - `http://HOST-IP:8789` -> POS (prueba principal del flujo)
   - `http://HOST-IP:8788` -> Administrativo (opcional)
5. **Iniciar sesión con el usuario local** (los usuarios viven en el backend local del host; si no existen,
   el técnico los crea/vincula). La tablet consume el mismo SQLite del host via HTTP, no comparte la base.
6. **Probar el flujo**:
   - Vendedor en la tablet: arma la orden (agrega items, cantidades, cliente) y pulsa **"Armar orden"**.
     No pide IMEI en serializados (solo arma), no exige abrir caja, no muestra botones de cobro/pago.
   - Cajera (otra sesión/tablet o el host): abre su caja, entra a **Pendientes**, ve la orden con
     "Armada por <vendedor>", la retoma, asigna IMEI si aplica y cobra con su propia caja.
   - El `seller_id` queda registrado en la orden (estructura lista para comisiones).

Limitaciones recordadas:

- El host debe permanecer abierto con el/los clientes corriendo mientras la tablet lo usa.
- El modo vendedor (solo armar) se activa por PERMISO (`pos.orders.hold` sin `pos.checkout`), no por dispositivo.
- El API local sigue siendo loopback para las consolas técnicas; la tablet solo usa los renderers 8788/8789.
- SQLite nunca se comparte por red (SMB); solo HTTP.

## Pantalla tactil "Armar orden" (v0.2.15+)

Nueva pagina separada del POS normal, disenada para tablets Android. Vive en
`frontend/src/features/pos-armar/` y se sirve en la ruta `/pos/armar` del bundle POS.

- **Teclado on-screen propio** (`OnScreenKeyboard`): botones grandes (A-Z, 0-9, ESPACIO, BORRAR).
  NO usa el teclado del sistema Android, para que el tap no se pierda por el cierre del teclado virtual.
- **`ArmOrderScreen`**: buscador con teclado propio, grid de productos con tap=agregar directo,
  carrito lateral con totales y boton "Enviar a la cajera" que hace `POST /api/pos/orders`
  (mismo endpoint del flujo vendedor -> cajera).
- **Logica pura** en `armOrderLogic.ts` (keyAction/applyKey/normalizeSearch/canSearch/money).
- **Redireccion automatica**: `PosTerminal` redirige a `/pos/armar` si el usuario es
  `sellerOnlyMode` (`pos.orders.hold` sin `pos.checkout`). El POS de cajero se conserva intacto.
- Ruta: `frontend/src/routes/_authed/pos_.armar.tsx` (el guion bajo evita anidarla dentro de
  `pos.tsx`; regenera `routeTree.gen.ts` al hacer build).

### Correccion tactil y de layout (v0.2.17)

- `/pos/armar` se considera una ruta POS de pantalla completa y no carga el sidebar ni la barra
  administrativa, incluso cuando se abre desde navegador por la red local.
- Los resultados de productos usan `TapButton`: confirma la seleccion desde `pointerdown` para
  evitar que Android pierda la accion cuando emite `pointercancel` por scroll o teclado.
- El pedido y su resumen se distribuyen en dos columnas en tablet y se apilan solo en telefonos.
- El diagnostico visible de v0.2.16 fue retirado. El comportamiento queda cubierto por pruebas que
  simulan un toque Android y verifican que el producto aparece en el pedido con el total correcto.

### Correccion de montaje de la pantalla de armado (v0.2.18)

- La ruta tactil se declara como hermana de `/pos`, conservando la URL publica `/pos/armar`.
- Esto evita que TanStack Router intente renderizarla dentro de `pos.tsx`, que contiene el terminal
  de cobro y no un `Outlet` para rutas hijas.
- Una prueba sobre el arbol de rutas exige que `/pos/armar` dependa directamente del layout
  autenticado; asi se valida que la tablet recibe `ArmOrderScreen` y no `PosTerminal`.

## Proceso completo de actualizacion (runbook operativo)

Este flujo se usa para publicar cualquier fix o feature a los clientes de escritorio
(admin, pos, technician). El backend Laravel viaja DENTRO de cada cliente, asi que cada
cambio de la app = un release nuevo del cliente.

### Paso 1 - Cambios y tests

```bash
# Backend: correr los tests del modulo afectado
php vendor/bin/phpunit -c phpunit.sqlite.xml tests/Feature/<Modulo>/

# Frontend: typecheck + tests
cd frontend
pnpm typecheck
pnpm vitest run
```

### Paso 2 - Commit y push

```bash
git add -A
git commit -m "tipo(scope): descripcion"
git push origin main          # corre el pre-push hook (suite completa, puede tardar)
```

### Paso 3 - Subir version y publicar

```bash
# 1) Bump version en frontend/package.json (electron-updater solo actualiza si la version es MAYOR)
# 2) Commit + push del bump
git add frontend/package.json && git commit -m "chore: bump version to 0.2.17" && git push origin main

# 3) Publicar el/los clientes (uno por cliente)
gh workflow run release.yml -f client=pos         --repo gamijoam/inventarioarens
gh workflow run release.yml -f client=admin       --repo gamijoam/inventarioarens
gh workflow run release.yml -f client=technician  --repo gamijoam/inventarioarens

# 4) Seguir el estado y verificar
gh run watch --repo gamijoam/inventarioarens
gh release view v0.2.17-pos --repo gamijoam/inventarioarens --json tagName,isDraft,assets
```

### Paso 4 - Instalar en las PC (cuando el auto-update no es suficiente)

Si un cliente no puede auto-actualizarse (p. ej. version muy vieja sin updater, o falla el
supervisor), instalar manual el `.exe` del release:

```bash
# Descargar
gh release download v0.2.17-pos --repo gamijoam/inventarioarens --pattern "*.exe" --dir $env:TEMP\installers

# Instalar silencioso (NSIS)
Start-Process "$env:TEMP\installers\Sistema-de-Inventario-POS-0.2.17.exe" -ArgumentList "/S" -Wait

# Verificar version instalada (desde el asar)
node -e "const asar=require('@electron/asar'); const p=asar.extractFile('C:/Users/gafit/AppData/Local/Programs/Sistema-de-Inventario-POS/resources/app.asar','package.json'); console.log(JSON.parse(p.toString()).version)"
```

IMPORTANTE: si hubo una version corriendo antes, **cerrar todos los procesos** del cliente y
del supervisor PHP (`Get-Process -Name "*Sistema*","*POS*","php" | Stop-Process -Force`) y
**limpiar locks** (`%APPDATA%\InventarioArens\.runtime-supervisor.lock`, `.pid`) antes de
reabrir, para que el backend nuevo tome el puerto 8787.

### Paso 5 - Deploy del backend a la nube (si el cambio lo requiere)

```bash
ssh root@212.28.176.157   # password: GaboMac12
cd /opt/inventarioarens-cloud
sudo /usr/bin/env git pull
sudo /usr/bin/env composer install --no-dev --optimize-autoloader
sudo /usr/bin/env php artisan optimize:clear
sudo /usr/bin/env php artisan migrate --force
# Si hubo cambios de permisos/roles:
sudo /usr/bin/env php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

Los permisos NO viajan por sync; cada nodo los siembra desde `BasePermissions`. En local,
re-correr `php artisan sync:prepare-local <slug> <empresa> <email>` (idempotente) o re-vincular
desde el Soporte Tecnico para que el permiso nuevo exista en el SQLite local.

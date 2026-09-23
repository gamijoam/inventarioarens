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

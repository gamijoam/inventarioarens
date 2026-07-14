# AGENTS.md — Guía persistente para opencode en INVENTARIOARENS

> Este archivo es leído por opencode (y por otros agentes IA que respeten el estándar `AGENTS.md`) al
> inicio de cada sesión. Si algo en el proyecto cambia de forma estructural, **actualizarlo aquí primero**.

---

## 1. Qué es este proyecto

**INVENTARIOARENS** es un SaaS modular **multi-tenant** de gestión de inventario + punto de venta,
escrito en **Laravel 13 / PHP 8.3-8.4 / PostgreSQL**. Es un **backend API REST puro** que se consume
desde un cliente HTTP.

**Estado del frontend (2026-07-13)**: se eliminaron por completo los frontends anteriores
(portal web Blade/JS vanilla + WPF escritorio). El nuevo cliente frontend se construirá en una
fase posterior como **aplicación web moderna** (SPA/PWA) que se ejecuta en navegador — tanto local
como en la nube — y consume este backend vía `/api/*`. **Mientras tanto, no hay frontend en este repo.**

| Capa | Stack |
|---|---|
| Backend | Laravel 13 + PHP 8.3+ + PostgreSQL 16 (prod) / 17 (docker dev) / 15 (CI) |
| Auth | `Authorization: Bearer <token>` + `X-Tenant: <slug>` |
| Multi-tenant | Single-DB con `tenant_id` + global scope |
| Frontend | **(pendiente)** — nueva app web por construir |

**Contexto de mercado (Venezuelano)**: moneda base **USD**, operativa **VES**, con tipos de tasa
(`BCV`, `PARALELO`, tienda) y snapshot de rate en cada movimiento monetario.

**Infraestructura real**:
- Local: Windows + Laragon + PHP 8.4.23 (`C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe`)
- Local DB: PostgreSQL 16 en `127.0.0.1:5434`, DB `inventory_arens`, user `inventory_arens`/`secret`
- VPS nube: `217.216.80.158` (Contabo Ubuntu 24.04), Nginx + PHP-FPM en `/opt/inventarioarens-cloud/public`
- DB nube: PostgreSQL 16 nativo (NO Docker) en `127.0.0.1:5432`, DB `inventory_arens`, user `postgres`
- Dominio público: **`https://app.miinventariofacil.com/api`** (HTTPS Let's Encrypt)
- SSH al VPS: `root@217.216.80.158` con key `C:\Users\gafit\.ssh\webadmin-vps` (instalada en `~/.ssh/authorized_keys` el 2026-07-13). **NO existe usuario `webadmin`** en el host: los scripts de deploy usan root directo. `scripts/deploy-platform-master.sh` ya está actualizado para no usar `sudo -u webadmin`.

---

## 2. ⚠️ REGLA CRÍTICA — NO CONFUNDIR con MiInventarioFácil

El usuario tiene **DOS productos SaaS** que comparten marca pero viven en VPSs distintos con stacks
distintos. Esto ya causó errores graves en sesiones previas:

| Proyecto | VPS | Backend | DBs | SSH key |
|---|---|---|---|---|
| **INVENTARIOARENS (ESTE)** | `217.216.80.158` | Laravel 13 | `inventory_arens` | `webadmin-vps` |
| MiInventarioFácil (OTRO) | `212.28.176.157` | FastAPI 2.2.0 | `invensoft_qa` / `invensoft_prod` | `bloqueo_vps_mavis` |

Antes de tocar la nube, **confirmar siempre**:
1. SSH al VPS correcto: `ssh -i webadmin-vps root@217.216.80.158`
2. DB correcta: `inventory_arens` (no `invensoft_*` ni `invensoft_qa`).
3. Backend correcto: Laravel (no FastAPI).

Hay más detalle en `.harness/docs/INVENTARIOARENS_PROJECT_FACTS.md` — leerlo si hay duda.

---

## 3. Estructura del repositorio

```
INVENTARIOARENS/
├── app/
│   ├── Http/Controllers/Controller.php   ← SOLO base. El resto de controllers vive en módulos.
│   ├── Models/User.php                   ← ÚNICO model fuera de módulos.
│   ├── Modules/                          ← 35 módulos con MVC propio cada uno.
│   ├── Providers/                        ← AppServiceProvider.
│   └── Support/
│       ├── Permissions/BasePermissions.php          ← Catálogo de 101 permisos + 6 roles.
│       ├── Performance/PerformanceProbe.php          ← Métricas PERF OK/LENTO BACKEND.
│       └── Tenancy/                                   ← TenantManager, TenantScope, BelongsToTenant trait.
├── bootstrap/
│   ├── app.php                            ← Middleware aliases 'api.auth' + 'tenant', comandos.
│   └── providers.php
├── config/                                ← app, auth, cache, database, filesystems, queue, session, services.
├── database/
│   ├── migrations/                        ← 72+ migraciones (cronología 2026-07-02 → hoy).
│   ├── seeders/{DatabaseSeeder,RolesAndPermissionsSeeder,DemoDataSeeder,MultiCompanyLoginDemoSeeder}.php
│   └── factories/UserFactory.php
├── docs/                                  ← ~40 .md de diseño, implementación, auditoría e historia.
├── routes/
│   ├── api.php                            ← Thin aggregator; carga routes.php de cada módulo bajo 'api.auth'+'tenant'.
│   └── console.php
├── scripts/                               ← PowerShell/VBS del sync worker + bootstrap VPS + backfills.
├── tests/                                 ← ~390 tests Feature con --process-isolation.
│   └── Feature/                           ← Agrupados por módulo.
├── storage/app/sync-worker/               ← (generado) sync-config.json por empresa.
├── .harness/                              ← DE OTRO AGENTE. NO TOCAR sin OK del usuario.
├── .codex/                                ← DE OTRO AGENTE. NO TOCAR sin OK.
├── .githooks/pre-push                     ← Hook de tests pre-push. NO TOCAR.
├── .github/workflows/ci.yml               ← CI: phpunit job.
├── docker-compose.yml                     ← Stack dev local (app, app_test, postgres 17).
├── BUILD.md, composer.json, phpunit.xml, README.md
└── AGENTS.md                              ← ESTE ARCHIVO.
```

**Carpetas eliminadas el 2026-07-13** (frontend anterior):
- ❌ `resources/views/`, `resources/js/`, `resources/css/` — Blade + JS vanilla del portal web admin y welcome.
- ❌ `desktop/`, `desktop/InventoryDesktop.slnx` — los 3 proyectos WPF (app, XamlSmoke, configurador).
- ❌ `tests/e2e/`, `playwright.config.js` — único spec del portal admin.
- ❌ `package.json`, `pnpm-lock.yaml`, `vite.config.js`, `.npmrc`, `node_modules/`, `.pnpm-store/`, `public/build/` — bundler Vite + Playwright.

---

## 4. Multi-tenancy — Cómo funciona

**NO** es schema-per-tenant ni DB-per-tenant. Es **single-DB con `tenant_id` + global scope**:

- **Trait**: `App\Support\Tenancy\Concerns\BelongsToTenant` en cada modelo de negocio.
  - `bootBelongsToTenant()` registra `TenantScope` global.
  - En `creating`, autollena `tenant_id` desde `TenantManager::require()->id`.
- **Scope**: `TenantScope` aplica `where tenant_id = current` (no-op si no hay tenant resuelto).
- **Manager**: `TenantManager` es singleton `scoped()` que mantiene el tenant actual del request.
- **Middleware** (en orden de ejecución):
  1. `api.auth` (`AuthenticateApiToken`) — valida `Bearer` token, hashea, verifica no expirado/revocado.
  2. `tenant` (`ResolveTenant`) — resuelve tenant desde `X-Tenant` header → route param → `?tenant=` → dominio. **Valida que el token pertenece a ese tenant** (sino 403).
- **Spatie Permission** con `teams = tenant_id`: un mismo email tiene roles distintos por empresa.
- **Llaves únicas compuestas**: `['tenant_id', 'sku']`, `['tenant_id', 'document_type', 'document_number']`, etc.
- **Cross-tenant por diseño** (sin scope): `tenants`, `tenant_user`, `inventory_transfer_requests`, `auth_tokens`.

**Implicaciones al escribir código**:
- Cualquier modelo nuevo de negocio DEBE usar `use BelongsToTenant`.
- Las llaves únicas DEBEN ser compuestas con `tenant_id`.
- Un endpoint nuevo DEBE chequearse con un test cross-tenant (ver §9).
- FKs entre tablas de negocio DEBEN ser compuestas `['tenant_id', 'id']` si la tabla padre es tenant-scoped.

---

## 5. Sync Local ↔ Nube

**Patrón: Local-First + Transactional Outbox bidireccional.**

- Tablas: `sync_nodes`, `sync_outbox`, `sync_inbox`, `sync_states`, `sync_tenant_readiness`.
- **Idempotencia**: cada evento tiene `event_uuid` único. Dedup en push y pull.
- **Polling** es la fuente confiable (15/30/60s). WebSocket es acelerador opcional, nunca fuente de verdad.
- **Polling excluye el propio node**: cada nodo NO recibe sus propios eventos de vuelta.
- **Excepciones por diseño**:
  - Eventos append-only (ventas, pagos, caja, kardex) — nunca se sobreescriben, solo se anexan + auditan.
  - Datos admin-managed (precios, tasas, permisos) — nube gana.
  - Productos — campos separados entre cloud-managed y local-operational.
  - Clientes — upsert por documento/teléfono/UUID.
- **Token de sync** vs **token de usuario**:
  - Token de usuario: `POST /api/auth/login`, expiración 30 días.
  - Token de sync: `POST /api/sync/tokens` (requiere manager auth), típicamente 365 días, vive en
    `storage/app/sync-worker/sync-config.json` **por empresa**.
- **ACK solo después de aplicar**: eventos fallidos permanecen en `sync_inbox` para retry.
- **Foto inicial**: cuando un nodo local nuevo se registra con su catálogo vacío, la nube genera
  automáticamente un snapshot inicial (`product.created`, `price_list.created`, etc.) marcado `sync_snapshot`.

**Worker en Windows**: Scheduled Task `SistemaInventarioSync-{tenant-slug}` cada 5 min, ejecuta
`scripts/run-sync-hidden.vbs` → `scripts/sync-worker.cmd start {tenant}`. Sobrevive reinicios.

**Comandos Artisan**: `sync:run`, `sync:daemon`, `sync:apply-inbox`, `sync:issue-token`,
`sync:prepare-local`, `sync:reset-readiness`.

---

## 6. Autenticación

Compartida por cualquier cliente (web, móvil, CLI) que consuma el backend:

1. `POST /api/auth/tenants {email}` (sin auth) → lista de tenants donde el user está `active`.
2. Usuario selecciona empresa → `POST /api/auth/login` con `X-Tenant: <slug>` + password →
   devuelve Bearer token + user + tenant + roles + permisos efectivos.
3. Cada llamada: `Authorization: Bearer <token>` + `X-Tenant: <slug>`.

**Platform Admin** (SaaS Master, sin tenant): `POST /api/auth/platform-login` emite token con
`tenant_id = null` para acceder a `/api/master/*`.

---

## 7. Stack — Versiones exactas

| Capa | Versión |
|---|---|
| PHP | 8.3 (mínimo) — local usa 8.4.23 via Laragon |
| Laravel | 13.8 |
| spatie/laravel-permission | 8.1 (con `teams`) |
| PHPUnit | 12.5.12 |
| Pint | 1.27 |
| PostgreSQL | 16 (prod + local) / 17-alpine (docker dev) / 15 (CI) |
| Composer scripts | `composer setup`, `composer dev`, `composer test` |

**Ya NO hay**: Vite, Tailwind, Playwright, npm/pnpm, MaterialDesignThemes, WPF.

---

## 8. Convenciones de código

### 8.1 Estructura por módulo (bajo `app/Modules/<Nombre>/`)
```
ModuleName/
├── Actions/         (opcional)
├── Controllers/
├── DTOs/            (opcional)
├── Exceptions/      (opcional)
├── Models/
├── Policies/
├── Requests/
├── Resources/
├── Services/
├── routes.php
└── ModuleServiceProvider.php (solo si necesita)
```

### 8.2 Routing
- `routes/api.php` es un aggregator; cada módulo declara su `routes.php` y se carga acá.
- Todas las rutas de API van bajo los middlewares `['api.auth', 'tenant']` excepto:
  - `auth/*` (login, me, logout, switch-tenant)
  - `bootstrap/*` (POST /api/bootstrap, **único endpoint realmente público**; requiere `APP_BOOTSTRAP_TOKEN` en .env y BD vacía)
- Todas las rutas están prefijadas con `/api` automáticamente.
- **No hay** `routes/web.php` — fue eliminado el 2026-07-13 junto con el frontend web. Si se reintroduce, debe ser solo para servir el bundle del nuevo frontend.

### 8.3 Modelos
- Modelos nuevos de negocio **deben** `use BelongsToTenant`.
- Constantes en MAYÚSCULAS con `_` (e.g. `STATUS_ACTIVE`, `TRACKING_SERIALIZED`).
- Llaves foráneas compuestas con `tenant_id` cuando la tabla padre es tenant-scoped.

### 8.4 Permisos (101 totales)
- Catálogo maestro: `App\Support\Permissions\BasePermissions::PERMISSIONS`.
- 6 roles predefinidos: `Owner`, `Administrador` (todos los perms), `Gerente` (78 perms; casi todos
  excepto `users.create/update/delete`, `roles.*`, `settings.manage`, `ai.configure`),
  `Vendedor` (32; sales/POS/caja), `Almacen` (37; inventario/traslados/compras), `Auditor` (26; read-only).
- Cada permission se nombra como `<modulo>.<verbo>` (e.g. `inventory_transfers.prepare`,
  `pos.checkout`, `cash_register.open`).
- Cada policy/método importante valida con `Gate::authorize` o `$request->user()->can(...)`.

### 8.5 Bootstrap inicial del SaaS

`POST /api/bootstrap` es el **único endpoint público** del sistema. Sirve para arrancar el SaaS desde
una base de datos completamente vacía sin necesidad de SSH al servidor para correr seeders.

- **Habilitado solo si** `APP_BOOTSTRAP_TOKEN` está definido en `.env` del backend.
- **Throttle**: `throttle:bootstrap` (3 req/hora por IP).
- **Falla con 422** si la BD ya tiene cualquier `User` o `Tenant`.
- Compara el token con `hash_equals()` y permite pasarlo en el body (`bootstrap_token`) o en el header
  `X-Bootstrap-Token`.
- Loguea intentos exitosos (`bootstrap.completed`) y rechazados (`bootstrap.rejected`) en `audit_logs`.
- Después del primer uso exitoso se desactiva vaciando la variable de entorno.

Crea el primer **Platform Admin** (`is_platform_admin=true`) y, opcionalmente, también un tenant inicial
con el admin asignado como `Administrador` con los 101 permisos.

**Tests**: `tests/Feature/Bootstrap/BootstrapApiTest.php` (17 tests, 81 aserciones).
**Docs**: `docs/BOOTSTRAP_API.md`.

### 8.6 Dinero
- **Doble cuenta** en cada movimiento monetario: `*_base_amount` (USD) + `*_local_amount` (VES).
- Snapshot del rate: `exchange_rate_type_id`, `exchange_rate_type_code`, `exchange_rate` numérico.
- NUNCA recalcular historicos — el rate congelado en su fila es la verdad.

### 8.7 Estilo
- Pint 1.27 (`vendor/bin/pint` antes de commit).
- No emojis en código a menos que el usuario lo pida.
- No agregar comentarios a menos que el usuario lo pida.
- Comentarios existentes en español.

---

## 9. Tests

### 9.1 Correr localmente
```bash
# Suite completa
php vendor/bin/phpunit

# Por módulo
php vendor/bin/phpunit tests/Feature/InventoryTransfers/

# Un archivo
php vendor/bin/phpunit tests/Feature/AdminPortal/AdminTransferActionsTest.php

# Con isolation (si hay "duplicate table" en local)
php vendor/bin/phpunit --process-isolation
```

**No hay tests E2E** (Playwright fue eliminado con el frontend web). Si se reintroduce un frontend,
agregar specs E2E en una carpeta dedicada (probablemente `tests/e2e/` o `frontend/tests/e2e/` según
dónde viva el código del cliente).

### 9.2 Tests cross-tenant (OBLIGATORIOS al agregar endpoints)
Patrón: crear tenant A y tenant B, usuario en cada uno con permisos completos, intentar acción
cross-tenant, esperar 403 (o 404 si oculta existencia), verificar que el recurso en A no cambió.

```bash
php vendor/bin/phpunit --filter "cross_tenant|other_tenant|detail_audit|standard_api_index_does_not_leak"
```

### 9.3 Pre-push gate
`bin/pre-push.php` corre toda la suite antes de cada push. NO hacer push si falla (emergencia:
`git push --no-verify`, solo con justificación).

### 9.4 Disciplina de tests (OBLIGATORIA)

**Regla innegociable**: la suite de pruebas **SIEMPRE se debe ejecutar** después de crear nuevas
cosas o funciones para verificar que **no se rompe la funcionalidad existente**.

**Y al revés**: toda herramienta nueva / funcionalidad nueva / cambio de comportamiento **debe venir
acompañado de sus tests correspondientes**. Sin tests, no se considera terminado.

Reglas concretas:

1. **Después de cualquier cambio de código** (controller, service, model, request, policy, route,
   migration, sync event, comando Artisan), correr **al menos** los tests del módulo
   afectado ANTES de declararlo listo:
   ```bash
   php vendor/bin/phpunit tests/Feature/<ModuloAfectado>/
   ```
   Si hay dudas de impacto, correr la suite completa: `php vendor/bin/phpunit`.

2. **Funcionalidad nueva = tests nuevos obligatorios**. Mínimo:
   - Test Feature del endpoint nuevo (happy path + caso de error/validación + caso de permiso).
   - Si es multi-tenant: test cross-tenant (ver §9.2).
   - Si es sync: test que cubra el evento en `sync_outbox` y su aplicación por el applier.
   - Si es POS / caja / inventario: test que verifique el movimiento de stock (`StockMovement`)
     y el saldo (`StockBalance`) resultante.
   - Si es dinero: test que verifique doble cuenta (`*_base_amount` + `*_local_amount`) y el
     snapshot del rate.

3. **Tests deben vivir junto al código que prueban**:
   - Backend: `tests/Feature/<Modulo>/<Concepto>Test.php`.
   - Frontend (cuando exista): vivir en el repo del frontend, no en este.

4. **Antes de pedir confirmación de "listo" al usuario**, el agente **debe** mostrar el resultado de
   los tests (verde o rojo, cuántos pasaron/fallaron). No entregar trabajo sin verificar.

5. **Si un test se rompe por un cambio propio**, **NO se silencia ni se borra**. Se arregla el
   código. Si el test estaba mal escrito, se corrige con justificación.

6. **Si se agrega una herramienta nueva** (comando Artisan, script en `scripts/`, endpoint de
   diagnóstico, utilería), crear test unitario o Feature que cubra al menos el caso feliz + un
   caso de fallo. No dejar herramientas sin cobertura.

7. **Migraciones nuevas** (no destructivas): agregar test que verifique que la nueva columna /
   tabla es accesible desde el modelo esperado. Si la migración cambia schema, correr `RefreshDatabase`
   completo y verificar 0 errores.

8. **Si modificás código de sync** (event applier, outbox, transport, snapshot inicial), correr
   **además** el smoke test si está disponible:
   ```bash
   .\scripts\sync-smoke-test.ps1 -CloudApiUrl https://app.miinventariofacil.com/api
   ```

Anti-patrones prohibidos:
- ❌ Marcar tarea como completa sin correr tests.
- ❌ Commitear cambios que rompen tests existentes sin arreglar la regresión.
- ❌ Agregar feature sin test ("lo testeo a mano").
- ❌ Borrar o `skip()` un test que falla porque "molesta".
- ❌ Decir "los tests pasan localmente" sin haberlos corrido realmente.

---

## 10. Build y Deploy

### 10.1 Setup local (primera vez)
```bash
git clone https://github.com/gamijoam/inventarioarens.git
cd inventarioarens
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
```

**No hay** `pnpm install` ni `pnpm run build` — el frontend se sirve aparte (o se construirá
en una fase posterior). El repo es **backend puro**.

### 10.2 Dev con hot reload (opcional)
```bash
php artisan serve        # Laravel dev server
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0
# O todo junto:
composer dev
```

### 10.3 Deploy al VPS (`217.216.80.158`)
```bash
ssh -i webadmin-vps root@217.216.80.158
cd /opt/inventarioarens-cloud
sudo /usr/bin/env git pull
sudo /usr/bin/env composer install --no-dev --optimize-autoloader
sudo /usr/bin/env php artisan optimize:clear
sudo /usr/bin/env php artisan migrate --force
```

**🚫 JAMÁS usar `php artisan view:cache`** — cachea Blade y los cambios no se ven hasta el próximo
`view:clear`. `optimize:clear` ya lo hace.

### 10.4 Stack del VPS
- Nginx + PHP-FPM 8.4 + PostgreSQL 16 nativo (NO Docker).
- HTTPS con Let's Encrypt.
- DNS A: `app.miinventariofacil.com → 217.216.80.158`.
- Bootstrap: `scripts/cloud-api-bootstrap-vps.sh`.

---

## 11. Diagnóstico rápido

| Síntoma | Causa probable | Fix |
|---|---|---|
| 401 en API tras deploy | Sesión cacheada con token viejo | `php artisan optimize:clear` |
| Worker abre ventana negra | Scheduled Task apunta al .cmd directo, no al VBS | Re-registrar con `scripts/sync-worker-task.ps1 install -TenantSlug <slug>` |
| Cambios locales no llegan a la nube | Worker no corriendo o token vencido | `php artisan sync:status {tenant}` + reinstalar task |
| Evento `ignored` en sync_inbox | Falta tipo conocido por el applier | `php artisan sync:apply-inbox <tenant> --limit=200` |
| Tests "duplicate table" local | Concurrencia en DB testing | Correr archivo por archivo o `--process-isolation` |
| Login falla con 401 multi-tenant | Token no coincide con X-Tenant | Re-loguear seleccionando empresa correcta |

---

## 12. Documentos de referencia (docs/)

**Arquitectura y diseño** (leer antes de cambios estructurales):
- `docs/ARCHITECTURE.md` — fuente de verdad arquitectural del backend.
- `docs/MODULES.md` — mapa de módulos.
- `docs/API.md` — referencia de endpoints.
- `docs/IMPLEMENTATION_LOG.md` — bitácora cronológica de cambios.
- `docs/BOOTSTRAP_API.md` — endpoints del módulo Bootstrap (instalación inicial de tenants).

**Infra y deploy**:
- `docs/BUILD.md` — setup local + deploy + CI.
- `docs/ENTORNO_VPS_POSTGRES_LOCAL_2026-07-05.md`
- `docs/ENTORNO_LOCAL_LARAGON_POSTGRES_2026-07-05.md`
- `docs/DOMINIO_APP_MIINVENTARIOFACIL_VPS_2026-07-07.md`
- `docs/API_NUBE_PERMANENTE_Y_PRUEBA_DOMINIO_2026-07-07.md`
- `docs/DEPLOY_PLATFORM_MASTER_2026-07-13.md`

**Dominio**:
- `docs/AISLAMIENTO_MULTIEMPRESA_2026-07-05.md` — multi-tenancy.
- `docs/MODULO_METODOS_PAGO.md`
- `docs/MODULO_TASAS_CAMBIO_2026-07-08.md`
- `docs/PLAN_MODULO_TRASLADOS_LOGISTICOS_2026-07-09.md` + fases 1-7 (backend).
- `docs/PERMISSIONS_HIERARCHY_DESIGN_2026-07-13.md`
- `docs/SCOPES_DESIGN_2026-07-13.md`

**Sync**:
- `docs/SINCRONIZACION_LOCAL_NUBE_2026-07-05.md` — diseño general.
- `docs/SYNC_API_TRANSPORTE_2026-07-05.md` — endpoints.
- `docs/SYNC_AUTO_WORKER_Y_API_PERMANENTE_2026-07-06.md`
- `docs/SYNC_WORKER_WINDOWS_TAREA_PROGRAMADA_2026-07-06.md`
- `docs/SYNC_WORKER_WINDOWS_OPERACION_2026-07-06.md`
- `docs/SYNC_OPERATIVO_POR_EMPRESA_2026-07-06.md`
- `docs/SYNC_SMOKE_TEST_LOCAL_NUBE_2026-07-05.md`
- `docs/SYNC_WORKER_LOCAL_NUBE_2026-07-05.md`

**SaaS Master / Platform Admin**:
- `docs/INSTRUCCIONES_FRONTEND_SAAS_MASTER.md` — contrato API para `/api/master/*`.
- `docs/INSTRUCCIONES_FRONTEND_PERMISSIONS.md` — 3 niveles de permisos.
- `docs/INSTRUCCIONES_FRONTEND_SCOPES.md` — scopes por recurso.

**Auditoría backend 2026-07-11**:
- `docs/AUDIT_2026-07-11/00_RESUMEN_EJECUTIVO.md`
- `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` — contrato API para futuro frontend.
- `docs/AUDIT_2026-07-11/ROADMAP.md` — items P0-P4.
- `docs/AUDIT_2026-07-11/{01..10}_*.md` — auditorías detalle por módulo.

**Frontend nuevo (pendiente de construir)**:
- Stack por decidir: SPA (React/Vue/Svelte) o PWA, servido aparte o vía Laravel.
- Debe consumir los endpoints documentados en `docs/API.md` y respetar
  `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` + `docs/INSTRUCCIONES_FRONTEND_*.md`.

---

## 13. Comandos rápidos de referencia

```bash
# Backend
composer install
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=MultiCompanyLoginDemoSeeder --force
php artisan db:seed --class=DemoDataSeeder --force
php artisan optimize:clear
php artisan route:clear
php artisan config:cache

# Tests
php vendor/bin/phpunit
php vendor/bin/phpunit --filter "cross_tenant"
php vendor/bin/phpunit --process-isolation
vendor/bin/pint                       # code style

# Sync
php artisan sync:prepare-local demo-valencia "Demo Valencia" admin@test.test
php artisan sync:issue-token demo-valencia admin@test.test --name=worker --days=365
php artisan sync:run demo-valencia
php artisan sync:apply-inbox demo-valencia --limit=200

# Tenant Admin (Administrador dentro de una empresa existente)
php artisan access:promote-admin gerente.valencia@demo.test

# Platform Admin (SaaS Master, nivel global)
php artisan access:create-platform-admin "Nombre Admin" admin@arens.test
php artisan access:create-platform-admin "Nombre Admin" admin@arens.test --password=Secret1234

# VPS
ssh -i C:\Users\gafit\.ssh\webadmin-vps root@217.216.80.158
ssh -i C:\Users\gafit\.ssh\webadmin-vps root@217.216.80.158 "sudo -u postgres psql -d inventory_arens -c 'SELECT 1'"

# Diagnóstico local
Test-NetConnection 217.216.80.158 -Port 5432
Test-NetConnection app.miinventariofacil.com -Port 443
```

---

## 14. Lo que NO debo hacer

- ❌ Agregar migraciones con timestamps anteriores a `2026_07_10_xxxxxx` sin justificación.
- ❌ Crear modelos de negocio sin `use BelongsToTenant`.
- ❌ Crear FKs a tablas tenant-scoped sin composite `['tenant_id', 'id']`.
- ❌ Usar `php artisan view:cache`.
- ❌ Saltar el pre-push hook sin justificación documentada.
- ❌ Confundir INVENTARIOARENS con MiInventarioFácil (ver §2).
- ❌ Tocar `.harness/`, `.codex/`, `.githooks/`, `.github/workflows/` sin OK explícito.
- ❌ Recalcular rates históricos — los snapshots son la verdad.
- ❌ Sobreescribir ventas/pagos/caja (deben ser append-only + auditados).
- ❌ Borrar `.env` ni `.env.example`.
- ❌ Entregar código nuevo o modificados sin correr tests (ver §9.4).
- ❌ Crear feature/herramienta sin sus tests asociados.
- ❌ Recrear frontend dentro de este repo sin conversación previa. El frontend nuevo se construirá
  como proyecto separado (probablemente en otro directorio o repo) que consuma este backend vía
  `/api/*`. Si el usuario pide meter el frontend en este repo, abrir conversación sobre stack
  (React/Vue/Svelte/SvelteKit/Next/etc.) y tooling antes de empezar.

---

## 15. Cuando algo cambia de forma estructural

Si pasa algo que afecte decisiones futuras (nueva convención, nuevo VPS, nueva regla), actualizar
**primero este archivo** y después los docs en `docs/`. Las prioridades son:

1. Multi-tenancy rules → actualizar §4.
2. Sync semantics → actualizar §5.
3. Stack o versiones → actualizar §7.
4. Deploy/CI → actualizar §10 + §13.
5. Reglas críticas operativas → agregar a §11 o §14.
6. Contexto del VPS/proyecto → actualizar §1, §2 y `.harness/docs/INVENTARIOARENS_PROJECT_FACTS.md`.
7. Si se introduce un nuevo frontend → crear nueva sección §X con su stack, estructura y reglas,
   y actualizar §3.

---

## 16. Auditoría de backend 2026-07-11

El backend fue auditado el 2026-07-11 (10 agentes en paralelo, scope `app/`, `database/`, `routes/`, `tests/`).
Documentación detallada en `docs/AUDIT_2026-07-11/`:

- **Score general:** 6.8/10.
- **Resumen ejecutivo:** `docs/AUDIT_2026-07-11/00_RESUMEN_EJECUTIVO.md`.
- **Auditorías detalle:** `01_MULTI_TENANCY.md` (8.5), `02_AUTH_SEGURIDAD.md` (6.5), `03_SYNC_ENGINE.md` (6), `04_INVENTARIO_IMEI.md` (6), `05_POS_CAJA_TASAS.md` (7), `06_TRASLADOS.md` (7), `07_CXC_CXP_GARANTIAS.md` (6.5), `08_API_DESIGN.md` (7), `09_PERFORMANCE.md` (5.5), `10_CALIDAD_TESTS.md` (6-7).
- **Roadmap tachable:** `docs/AUDIT_2026-07-11/ROADMAP.md` (P0/P1/P2/P3/P4 con status).
- **Contrato para frontend:** `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` (referencia útil para el frontend nuevo).

**Regla de oro:** después de cada fix, actualizar el item correspondiente en `ROADMAP.md` cambiando
`- [ ]` → `- [x] — FECHA — descripción corta`. Si el fix descubre un nuevo issue, agregarlo al final
del documento de auditoría correspondiente.
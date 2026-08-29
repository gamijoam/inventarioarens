# AGENTS.md — Guía persistente para opencode en INVENTARIOARENS

> Este archivo es leído por opencode (y por otros agentes IA que respeten el estándar `AGENTS.md`) al
> inicio de cada sesión. Si algo en el proyecto cambia de forma estructural, **actualizarlo aquí primero**.

---

## 1. Qué es este proyecto

**INVENTARIOARENS** es un SaaS modular **multi-tenant** de gestión de inventario + punto de venta,
escrito en **Laravel 13 / PHP 8.3-8.4 / PostgreSQL**. Es un **backend API REST puro** que se consume
desde un cliente HTTP.

**Estado del frontend (2026-08-05)**: se eliminaron por completo los frontends anteriores
(portal web Blade/JS vanilla + WPF escritorio). La nueva SPA (Vite + React 18 + TS) vive en
`frontend/`, consume el backend vía `/api/*` y se empaqueta como dos clientes Electron:
`Sistema de Inventario (Administrativo)` y `POS`. Ambos comparten backend, autenticación,
permisos, SQLite/local service y sincronización; el bundle POS queda restringido a sus rutas.
Diseño completo en `docs/FRONTEND_*.md`.

| Capa | Stack |
|---|---|
| Backend | Laravel 13 + PHP 8.3+ + PostgreSQL 16 (prod) / 17 (docker dev) / 15 (CI) |
| Auth | `Authorization: Bearer <token>` + `X-Tenant: <slug>` |
| Multi-tenant | Single-DB con `tenant_id` + global scope |
| Frontend | Vite + React 18 + TS + TanStack Query/Router + Tailwind 4 + Radix UI + Zustand + Electron (en `frontend/`) |

**Contexto de mercado (Venezuelano)**: moneda base **USD**, operativa **VES**, con tipos de tasa
(`BCV`, `PARALELO`, tienda) y snapshot de rate en cada movimiento monetario.

**Infraestructura real (2026-07-21, post-migración)**:
- Local: Windows + Laragon + PHP 8.4.23 (`C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe`)
- **Base de datos local canónica: SQLite** (`storage/framework/local-dev.sqlite` para desarrollo/pruebas). No asumir PostgreSQL al ejecutar comandos o tests localmente.
- **VPS nube canónico: `212.28.176.157` (Contabo Ubuntu 24.04, multi-tenant con Docker)** — migrado el 2026-07-21 desde `217.216.80.158` (viejo, destruido). INVENTARIOARENS corre nativo (PHP-FPM 8.4 + nginx local en `172.18.0.1:8080` + PostgreSQL 16) detrás de Traefik (Docker) que termina TLS y enruta `app.miinventariofacil.com` → `:8080`. Detalles en `docs/MIGRACION_VPS_2026-07-21.md`.
- **VPS nube viejo `217.216.80.158`: DESTRUIDO**. Solo relevante para rollback histórico.
- DB nube: PostgreSQL 16 nativo (NO Docker) en `127.0.0.1:5432` del VPS nuevo, DB `inventory_arens`, user `postgres`/`GaboMac12`
- Dominio público: **`https://app.miinventariofacil.com/api`** (HTTPS Let's Encrypt, cert en acme.json de Traefik, emitido 2026-06-05, vence 2026-09-03)
- SSH al VPS nuevo: `root@212.28.176.157` con clave `GaboMac12` (root password). **NO** usar la key SSH vieja `C:\Users\gafit\.ssh\webadmin-vps` (es del VPS destruido). Los scripts `scripts/deploy-platform-master.sh`, `scripts/cloud-api-bootstrap-vps.sh` y `scripts/sync_token.py` ya están actualizados al host nuevo.
- **Convención crítica**: el VPS nuevo tiene 27 contenedores Docker de OTROS productos (bloqueo, credifacil, avilacar, ferreteria, mi-inventario-whatsapp, traefik, redis_prod/qa, db_qa). **No tocar bajo ninguna circunstancia** sin OK explícito del usuario. INVENTARIOARENS corre en stack nativo (no Docker).

---

## 2. ⚠️ REGLA CRÍTICA — NO CONFUNDIR con MiInventarioFácil

Desde el 2026-07-21 el VPS es **compartido** (`212.28.176.157`). Ambos productos viven en el mismo
host pero en stacks completamente distintos. El usuario tuvo errores graves en sesiones previas
confundiéndolos:

| Proyecto | Mismo VPS | Backend stack | DBs | Cómo identificarlo |
|---|---|---|---|---|
| **INVENTARIOARENS (ESTE)** | `212.28.176.157` | Laravel 13 nativo (PHP-FPM + nginx en `:8080` detrás de Traefik) | `inventory_arens` (PostgreSQL nativo, NO Docker) | `ps aux | grep php-fpm` muestra workers `www-data`. `/opt/inventarioarens-cloud` existe. Proceso no es `docker-proxy`. |
| MiInventarioFácil (OTRO) | `212.28.176.157` | FastAPI 2.2.0 en Docker (`backend_prod_server`, `backend_qa`) | `invensoft_qa` / `invensoft_prod` (PostgreSQL en Docker `db_qa_server`) | `docker ps | grep backend` muestra el contenedor. `docker exec backend_prod_server ...`. |

Ambos productos comparten:
- **Misma IP pública** (`212.28.176.157`) — la única forma de diferenciarlos es por dominio
  (`app.miinventariofacil.com` vs `api.miinventariofacil.com`) y por subdominio (Cloudflare).
- **Misma UFW** —他俩 están en la misma máquina, por eso los cambios de firewall afectan a ambos.
- **Mismo Traefik** (Docker) — términos TLS y routes viven en `/root/deploy/core/traefik-config/`.
- **Misma PostgreSQL nativa** en `127.0.0.1:5432` — **PERO diferente DB** (`inventory_arens` vs `invensoft_*`).
  `pg_restore` o DROP de la DB equivocada borra el otro producto.

Antes de tocar la nube, **confirmar siempre**:
1. SSH al VPS: `ssh root@212.28.176.157` con password `GaboMac12`. **Si te piden key SSH es que estás en otro VPS.**
2. DB correcta: `psql -d inventory_arens` debe mostrar tablas como `products`, `pos_orders`,
   `sync_inbox` (NO `invensoft_*`).
3. Backend correcto: `ls /opt/inventarioarens-cloud/artisan` debe existir. **NO** debe haber
   contenedor `backend_prod_server` hablando por puerto 8000 con FastAPI.
4. Dominio antes de actuar: `dig api.miinventariofacil.com` (FastAPI) vs
   `dig app.miinventariofacil.com` (Laravel). Confundir el subdominio significa tocar el stack
   equivocado.

Hay más detalle en `.harness/docs/INVENTARIOARENS_PROJECT_FACTS.md` — leerlo si hay duda.

---

## 3. Estructura del repositorio

```
INVENTARIOARENS/
├── app/
│   ├── Http/Controllers/Controller.php   ← SOLO base. El resto de controllers vive en módulos.
│   ├── Models/User.php                   ← ÚNICO model fuera de módulos.
│   ├── Modules/                          ← 41 módulos con MVC propio cada uno.
│   ├── Providers/                        ← AppServiceProvider.
│   └── Support/
│       ├── Permissions/BasePermissions.php          ← Catálogo de 102 permisos + 6 roles.
│       ├── Performance/PerformanceProbe.php          ← Métricas PERF OK/LENTO BACKEND.
│       ├── Tenancy/                                   ← TenantManager, TenantScope, BelongsToTenant trait.
│       └── Lab/LabDayService.php                     ← Ciclo real de negocio del lab:day (ver docs/LAB_DAY_SIMULADO.md).
├── bootstrap/
│   ├── app.php                            ← Middleware aliases 'api.auth' + 'tenant', comandos.
│   └── providers.php
├── config/                                ← app, auth, cache, database, filesystems, queue, session, services.
├── database/
│   ├── migrations/                        ← 72+ migraciones (cronología 2026-07-02 → hoy).│   ├── seeders/{DatabaseSeeder,RolesAndPermissionsSeeder,DemoDataSeeder,MultiCompanyLoginDemoSeeder}.php
│   └── factories/UserFactory.php
├── docs/                                  ← ~45 .md de diseño, implementación, auditoría e historia.
├── frontend/                             ← SPA React + TS + TanStack + Tailwind + dos clientes Electron.
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

**Carpetas/tablas agregadas el 2026-07-14** (Fase 1 inventario):
- ✅ `brands`, `categories`, `tags`, `product_tag`, `product_category` (5 tablas nuevas para catalog).
- ✅ Columnas nuevas en `products`: `barcode`, `description`, `long_description`, `unit_of_measure`, `track_stock`, `brand_id`, `min_stock`, `max_stock`, `reorder_quantity`, `average_cost`, `image_url`.

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
- **Spatie Permission** con `teams = tenant_id` (la columna se llama `tenant_id` en `roles`, NO `team_id`; ver `config/permission.php`): un mismo email tiene roles distintos por empresa.

### 4.1 Jerarquía de tenants: grupos vs empresas

A partir de la Fase 1 (jul-2026), los tenants forman una jerarquía explicita:

- **Tenant Group** (`is_group = true`, `parent_id = null`): contenedor de una o mas empresas.
  - Tiene su propio `tenant_id` (puede operar como empresa si se quiere).
  - Su Owner (rol "Owner" con team_id = group.id) puede crear empresas hijas via `POST /api/tenant-groups/{group}/tenants`.
- **Tenant Spinoff** (`is_group = false`, `parent_id = group.id`): empresa hija del grupo.
  - Su admin (rol "Administrador" con team_id = spinoff.id) opera esa empresa.
- **Tenant root sin hijos**: funciona como empresa normal. Si no tiene spinoffs, opera igual que antes y su catalogo compartido queda limitado a ese tenant. Cuando tiene hijos, ese root actua como grupo y comparte catalogo/precios/tasas/metodos con sus spinoffs.

**Endpoints clave**:

- `POST /api/tenant-groups` — self-serve: crea grupo + tenant inicial. Asigna Owner al grupo y Administrador a la empresa al admin del payload.
- `GET  /api/tenant-groups` — lista grupos donde el user autenticado es Owner.
- `POST /api/tenant-groups/{group}/tenants` — crea un spinoff (empresa hija) del grupo. Requiere que el user sea Owner del grupo.
- `POST /api/master/groups` — solo platform admins: crea un grupo (sin tenant inicial).
- `POST /api/master/groups/{group}/tenants` — solo platform admins: crea spinoff bajo un grupo.
- `POST /api/tenants` — crea un spinoff dentro de un grupo (requiere `parent_group_id` en el payload, validacion en `StoreTenantRequest`).

**Reglas del modelo** (`app/Modules/Tenancy/Models/Tenant.php`):

- `isGroup()` retorna `is_group === true` (NO se infiere de `parent_id IS NULL`).
- `isSpinoff()` retorna `!isGroup() && parent_id !== null`.
- `scopeGroups()` y `scopeSpinoffs()` para queries globales.
- `boot()` auto-deriva `is_group` desde `parent_id` al crear (consistente con la convencion).
- `Tenant::isOwnedBy(User)` delega a `User::isOwnerOf(group)` que verifica membresia + CUALQUIER rol dentro del grupo (back-compat). Para rol estricto usar `User::isStrictOwnerOf(group)`.
- **Migracion**: `2026_07_16_110453_add_is_group_to_tenants_table` (backfill: tenants con `parent_id = null` se marcaron `is_group = true`).

**Implicaciones para codigo nuevo**:

- NO asumir que toda empresa tiene spinoffs. Una empresa normal puede quedar como tenant raiz sin hijos y seguir su flujo habitual.
- NO usar `whereNull('parent_id')` para detectar grupos — usar `where('is_group', true)` o el scope `Tenant::groups()`.
- Para que un user sea "Owner real" del grupo, usar `User::isOwnerOf(group)`.

**Cross-tenant por diseño** (sin scope): `tenants`, `tenant_user`, `inventory_transfer_requests`, `auth_tokens`.

Al crear un spinoff, el Group Owner creador queda auto-asociado como miembro activo del nuevo tenant para que esa empresa aparezca en `POST /api/auth/tenants`.

En `InventoryTransferRequests`, `origin_tenant_id` identifica a la empresa solicitante que recibirá el stock y `destination_tenant_id` a la empresa que responde y lo suministra. `from_warehouse_id` es el almacén receptor de la solicitante y `destination_warehouse_id` es el almacén de salida de la empresa que responde. Al aceptar, se genera la salida y se retiran los IMEIs en `destination_tenant_id`; luego se genera la entrada y se recrean disponibles en `origin_tenant_id`.



**Implicaciones al escribir código**:
- Cualquier modelo nuevo de negocio DEBE usar `use BelongsToTenant`.
- Las llaves únicas DEBEN ser compuestas con `tenant_id`.
- Un endpoint nuevo DEBE chequearse con un test cross-tenant (ver §9).
- FKs entre tablas de negocio DEBEN ser compuestas `['tenant_id', 'id']` si la tabla padre es tenant-scoped.

**Capacidades por tenant (2026-08-25)**:
- `tenant_capabilities` define los modulos contratados por empresa; no reemplaza roles, permisos ni scopes.
- Tenants existentes conservan todas las capacidades durante la migracion.
- Tenants nuevos creados por los servicios oficiales reciben `dashboard`, `catalog`, `inventory`, `customers` y `suppliers`.
- El backend valida capacidades con `capability:<key>` antes de ejecutar rutas opcionales.
- Login, `/api/auth/me` y `switch-tenant` exponen `capabilities[]` para React/Electron; el frontend nunca puede agregar capacidades.
- La administracion usa `GET/PATCH /api/tenant-capabilities` y requiere `settings.manage` para modificar.
- Fuente de catalogo: `App\Support\Capabilities\BaseCapabilities`; resolver: `TenantCapabilityService`.
- Contrato completo: `docs/MATRIZ_CAPACIDADES_CLIENTES_ELECTRON.md`.

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
  - ⚠️ **Roles y permisos NO viajan por sync**: cada nodo (nube y local) los siembra desde
    `BasePermissions` con el código. En la nube: `php artisan db:seed --class=RolesAndPermissionsSeeder --force`
    (idempotente) tras el deploy. En local: el permiso llega con el release del cliente; para nodos
    ya preparados re-correr `sync:prepare-local <slug> <empresa> <email>` (idempotente) o re-vincular
    desde el Soporte Técnico.
  - Productos — campos separados entre cloud-managed y local-operational.
  - Clientes — upsert por documento/teléfono/UUID.
- **Token de sync** vs **token de usuario**:
  - Token de usuario: `POST /api/auth/login`, expiración 30 días.
- Token de sync: `POST /api/sync/tokens` (requiere manager auth), típicamente 365 días, vive en
  `storage/app/sync-worker/sync-config.json` **por empresa**.
- **Vinculación grupal**: `POST /api/sync/group-pairing-codes` crea un código de un solo uso para
  un grupo completo. Al redimirlo se emite un token independiente para el grupo y cada empresa
  hija; nunca se usa un token global multi-tenant.
- **Mapeos local/cloud**: `sync_tenant_mappings` conserva la relación de tenants y
  `sync_entity_mappings` conserva la relación de sucursales, almacenes y productos. El applier debe
  resolver estas relaciones antes de insertar FKs o movimientos cross-tenant; los IDs locales no
  se consideran iguales a los IDs cloud.
- **ACK solo después de aplicar**: eventos fallidos permanecen en `sync_inbox` para retry.
- **Foto inicial**: cuando un nodo local nuevo se registra con su catálogo vacío, la nube genera
  automáticamente un snapshot inicial (`product.created`, `price_list.created`, etc.) marcado `sync_snapshot`.
- **Bootstrap inicial selectivo por grupo (2026-08-15)**: una instalación nueva puede redimir un
  código de pairing grupal indicando solo los tenants seleccionados. La nube emite un token
  independiente por tenant, prepara un paquete tenant-scoped con punto de corte y el local lo importa
  en SQLite antes de activar el worker incremental. El token grupal no se usa como credencial
  permanente. El contrato está en `docs/SYNC_BOOTSTRAP_SELECTIVO_GRUPOS.md`.

**Worker en Windows**: el Motor Local ejecuta un único servicio real
`SistemaInventarioSync` → `php artisan sync:daemon-all`. El supervisor relee
`storage/app/sync-worker/sync-config.json` y procesa de forma aislada cada empresa configurada.
Las tareas `SistemaInventarioSync-{tenant-slug}` son legado y el instalador del Motor las elimina
solo después de validar el nuevo servicio.

El procedimiento Windows para vincular un grupo completo, verificar sus cinco tenants y diagnosticar
tokens/mapeos sin exponer secretos está en `docs/WINDOWS_ELECTRON_VALIDATION.md` §6.7.1.

**Comandos Artisan**: `sync:run`, `sync:daemon`, `sync:apply-inbox`, `sync:issue-token`,
`sync:prepare-local`, `sync:reset-readiness`.

---

## 6. Autenticación

Compartida por cualquier cliente (web, móvil, CLI) que consuma el backend:

1. `POST /api/auth/tenants {email}` (sin auth) → lista de tenants donde el user está `active`.
2. Usuario selecciona empresa → `POST /api/auth/login` con `X-Tenant: <slug>` + password →
   devuelve Bearer token + user + tenant + roles + permisos efectivos.
3. Cada llamada: `Authorization: Bearer <token>` + `X-Tenant: <slug>`.

**Modalidades de transporte del token** (2026-07-14, Plan C hibrido):
- `Authorization: Bearer <token>` (header) — usado por sync worker, Postman, scripts PHP.
- Cookie httpOnly `auth_token=<token>` — usado por el frontend SPA (navegador).
  El backend acepta ambas simultaneamente. CSRF protection solo aplica a requests
  autenticados via cookie (exige `X-Requested-With: XMLHttpRequest` + Origin en la
  allowlist `app.allowed_origins_for_csrf` configurada via `APP_ALLOWED_ORIGINS_FOR_CSRF`
  en `.env`). Ver `docs/AUTH_COOKIE_API.md` para el contrato completo y la guia de integracion.

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

**Ya NO hay**: portal Blade/JS vanilla, MaterialDesignThemes ni WPF. El frontend actual usa Vite,
React, pnpm, Playwright y Electron; el backend continúa siendo API REST puro.

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

### 8.4 Permisos (102 totales)
- Catálogo maestro: `App\Support\Permissions\BasePermissions::PERMISSIONS`.
- 6 roles predefinidos: `Owner`, `Administrador` (todos los perms), `Gerente` (78 perms; casi todos
  excepto `users.create/update/delete`, `roles.*`, `settings.manage`, `ai.configure`),
  `Vendedor` (32; sales/POS/caja), `Almacen` (37; inventario/traslados/compras), `Auditor` (26; read-only).
- Cada permission se nombra como `<modulo>.<verbo>` (e.g. `inventory_transfers.prepare`,
  `pos.checkout`, `cash_register.open`).
- Cada policy/método importante valida con `Gate::authorize` o `$request->user()->can(...)`.

### 8.5 Catalogo de inventario (Fase 1)

Tablas `brands`, `categories` (con `parent_id` jerarquico), `tags` + pivots `product_tag` y
`product_category`. Modelo `Product` extendido con:

- `barcode` UNIQUE por tenant (lector de codigo de barras).
- `description` (corto) y `long_description` (HTML, max 50000).
- `unit_of_measure` ∈ `unit/kg/lt/m`.
- `track_stock` boolean (default true).
- `brand_id` FK nullable a `brands`.
- `min_stock`, `max_stock`, `reorder_quantity` decimales nullable — base del sistema de alertas.
- `average_cost` WAC legado/informativo, recalculado internamente por `InventoryValuationService` si aplica; no es fuente operativa ni contable.
- `image_url` URL opcional.

Endpoints (24 nuevos):

```
GET    /api/brands                         CRUD marcas
POST   /api/brands
GET    /api/brands/{brand}
PATCH  /api/brands/{brand}
DELETE /api/brands/{brand}

GET    /api/categories                     CRUD categorias (con tree jerarquico)
GET    /api/categories/tree                arbol con children[]
POST   /api/categories
GET    /api/categories/{category}
PATCH  /api/categories/{category}
DELETE /api/categories/{category}

GET    /api/tags                           CRUD tags (con color #RRGGBB)
POST   /api/tags
GET    /api/tags/{tag}
PATCH  /api/tags/{tag}
DELETE /api/tags/{tag}

PATCH  /api/products/{product}/categories  reemplaza categorias (syncWithPivotValues)
PATCH  /api/products/{product}/tags       reemplaza tags

GET    /api/products?brand_id=&category_id=&tag_id=&search=&tracking_type=
                                              filtros server-side nuevos

GET    /api/inventory-center/products/{id}/stock-status
GET    /api/inventory-center/reorder-suggestions
GET    /api/inventory-center/alerts-summary
```

**WAC legado/informativo**: `InventoryValuationService::recalculate(Product)` puede actualizar
`products.average_cost` desde los `stock_movements` con `unit_cost`, pero ese valor NO se usa para
precios, costo de ventas, devoluciones, margen, CxC/CxP, reportes ni asientos contables. No diseñar
código nuevo que dependa de `average_cost`.

**Regla de costeo vigente**: devoluciones y ajustes deben usar el costo histórico/snapshot de la
operación original, nunca el WAC vigente. El WAC puede permanecer calculado por compatibilidad
interna, pero no debe afectar ningún resultado visible u financiero.

**Alertas**: `InventoryAlertService` calcula status por producto:
- `out` (available <= 0), `critical` (available <= min/2), `low` (available <= min),
  `available`, `overstock` (available > max).

**Tests**: `tests/Feature/Products/{Brand,Category,Tag,ProductCatalog}ApiTest.php` (40 tests) +
`tests/Feature/Inventory/InventoryValuationTest.php` (3 tests) +
`tests/Feature/InventoryCenter/InventoryAlertsTest.php` (7 tests).

**Docs**: `docs/INVENTORY_CATALOG_API.md` y `docs/INVENTORY_ALERTS_API.md`.

### 8.5.1 Inventario fisico y alertas (Fase 3)

Tablas `warehouse_locations` (jerarquica), `stock_counts` + `stock_count_items` (cycle count),
`alert_history` (historial de alertas). `stock_balances.location_id` FK nullable con UNIQUE
parcial (con/sin location).

Endpoints (14 nuevos):

```
GET    /api/warehouses/{warehouse}/locations
POST   /api/warehouses/{warehouse}/locations
GET    /api/warehouses/{warehouse}/locations/{location}
PATCH  /api/warehouses/{warehouse}/locations/{location}
DELETE /api/warehouses/{warehouse}/locations/{location}

GET    /api/stock-counts                    lista con filtros
POST   /api/stock-counts                    crea (status=draft)
GET    /api/stock-counts/{count}
PATCH  /api/stock-counts/{count}
DELETE /api/stock-counts/{count}            cancela
POST   /api/stock-counts/{count}/snapshot   copia stock a items
POST   /api/stock-counts/{count}/start      draft -> capturing
POST   /api/stock-counts/{count}/capture    bulk captura
POST   /api/stock-counts/{count}/complete   genera adjustments + status=completed

GET    /api/alert-history                   lista con filtros
GET    /api/alert-history/{alert}
POST   /api/alert-history/{alert}/dismiss
```

**Cycle count**: `StockCountService` maneja todo el flujo. Al completar genera automaticamente
`StockMovement` de tipo `adjustment_in` o `adjustment_out` por la diferencia entre
`system_quantity` y `counted_quantity`, con `reference_type='stock_count'` para trazabilidad.

**Alert history**: `AlertHistoryService::snapshotAlerts($tenantId)` escanea productos activos
con stock bajo/sin stock y crea registros. Deduplicacion automatica: misma (alert_type,
subject_type, subject_id) en 24h no se duplica.

**Tests**: `tests/Feature/Warehouses/WarehouseLocationApiTest.php` (5 tests) +
`tests/Feature/Inventory/StockCountApiTest.php` (6 tests) +
`tests/Feature/Inventory/AlertHistoryServiceTest.php` (4 tests).

**Docs**: `docs/INVENTORY_PHASE3.md`.

### 8.6 Dinero
- **Doble cuenta** en cada movimiento monetario: `*_base_amount` (USD) + `*_local_amount` (VES).
- Snapshot del rate: `exchange_rate_type_id`, `exchange_rate_type_code`, `exchange_rate` numérico.
- NUNCA recalcular historicos — el rate congelado en su fila es la verdad.
- Las devoluciones procesadas generan nota de crédito clasificada como `customer_credit`; no es CxC ni CxP. `customer_credit` genera además un ledger append-only de saldo a favor. Un canje siempre crea una nueva venta y aplica el crédito sin modificar la venta original.

### 8.6.2 Fiscalización venezolana (fase en construcción)
- `FiscalTaxRate` es tenant-scoped; sus códigos son únicos por empresa y sus categorías son `taxable`, `exempt`, `exonerated` y `non_taxable`.
- Los productos pueden referenciar `fiscal_tax_rate_id`; la FK es compuesta por `tenant_id` y el sync replica la clasificación mediante `fiscal_tax_rate_code`, nunca mediante IDs locales.
- `FiscalTaxCalculator` calcula base imponible, exentos, exonerados, no gravados, IVA y total con el parámetro explícito `pricesIncludeTax`.
- `Sale`, `SaleItem` y `PosOrder` ya conservan los totales fiscales y el snapshot al confirmar/cobrar. El borrador usa cálculo provisional; la venta confirmada no se recalcula con tasas posteriores.
- Los precios de venta son netos. Las promociones reducen la base antes del IVA; cada componente de un combo calcula su tratamiento fiscal. Los combos heredan la alícuota del producto o pueden usar `fiscal_tax_mode=override` con un `fiscal_tax_rate_id` del mismo tenant.
- `fiscal_tax_source` y `fiscal_tax_override_code` conservan el origen del tratamiento en la línea para recalcular borradores sin perder el override. El sync replica códigos naturales y timestamps, nunca IDs locales.
- Las líneas de `SalesReturnItem` copian el snapshot fiscal de `SaleItem` al solicitar la devolución. Nota de crédito, saldo a favor, reembolso y CxC deben usar esos importes históricos, nunca consultar la alícuota vigente.
- `Customer.fiscal_name` conserva el nombre legal/fiscal separado del nombre comercial; si se omite al crear un cliente, se deriva de `name` y se replica en el payload de sync.
- `GET /api/reports/fiscal/iva` es un reporte interno tenant-scoped de ventas confirmadas, agrupado por snapshots fiscales y con desglose USD/VES. No es una factura, declaración ni emisión certificada.
- La emisión fiscal certificada y la impresora fiscal todavía no están integradas. El ticket sigue siendo comercial.
- Contratos: `docs/FISCAL_IDENTITY_API.md` y `docs/FISCAL_TAX_RATES_API.md`.
- Configuración frontend: `/settings/company` administra identidad/condición fiscal y
  `/settings/fiscal` administra alícuotas. El Centro de Inventario permite seleccionar productos y
  aplicar un tratamiento fiscal masivamente; `all_matching=true` procesa todos los resultados
  filtrados en cola y conserva clasificaciones explícitas salvo que el administrador active
  `overwrite_existing`.

### 8.6.1 Comisiones V2 — control dinamico
- El reporte de control por producto usa `sales`, `sale_items`, `pos_orders` y `pos_payments`; el ledger `commission_entries` conserva la comision append-only y no sustituye el detalle de ventas.
- La tabla muestra todas las columnas por defecto y permite ocultar/mostrar columnas, incluyendo metodos de pago y `commission_ves` sin recalcular tasas historicas.
- Los metodos de pago tienen codigo corto/etiqueta de reporte configurables; no hardcodear `P.M.` o `P.V.`.
- El equivalente USD de una venta VES usa el snapshot almacenado en la linea/pago. Nunca consultar la tasa actual para modificar historicos.
- Contrato inicial: `GET /api/commissions/control`; detalle y reglas en `docs/COMISIONES_V2_CONTROL_DINAMICO.md`.

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

### 9.3.1 Protocolo TDD y calidad (OBLIGATORIO)

Toda funcionalidad nueva, especialmente cambios de arquitectura o del frontend POS, debe seguir
este orden estricto:

1. **Tests primero**: escribir tests unitarios y de integración que definan los requisitos y el
   comportamiento esperado antes de crear o modificar la implementación.
2. **Validar el contrato**: revisar que los tests representan los requisitos del usuario, los
   permisos, los casos de error y el aislamiento multi-tenant. No implementar código todavía.
3. **Implementar**: crear o modificar el código para hacer pasar los tests. Si un test falla por una
   regresión propia, se corrige el código, no se debilita ni se elimina el test.
4. **Mutation testing**: ejecutar pruebas de mutaciones sobre el código y los tests afectados para
   comprobar que los tests detectan cambios incorrectos. Si hay mutantes sobrevivientes relevantes,
   agregar o fortalecer tests antes de continuar.
5. **Análisis estático y Clean Code**: ejecutar las herramientas disponibles (Pint, PHPStan/Psalm,
   ESLint, TypeScript, Prettier u otras adoptadas por el módulo), corregir hallazgos en el código y
   revisar nombres, responsabilidades, duplicación, complejidad y acoplamiento.
6. **Suite final**: ejecutar los tests unitarios, integración, feature y E2E afectados, además de la
   suite completa cuando el cambio tenga impacto transversal. Documentar resultados verdes, fallos
   preexistentes y riesgos residuales antes de declarar la tarea terminada.
7. **Tests de contrato entre capas (incidencia 2026-08-11)**: los tests unitarios de cada capa
   pueden estar verdes aunque el **contrato backend→JSON→Zod** esté roto. Reglas mínimas para toda
   entidad replicada por sync o consumida por el frontend:
   - Backend: el test del applier/endpoint debe assertar los **timestamps** (`created_at`,
     `updated_at`) de la fila insertada, no solo la existencia/columnas de negocio. Un insert con
     `updated_at` null deja al frontend descartando el recurso (ver `SyncEventApplierTest`,
     `FinancialSyncTest`).
   - Frontend: los tests del parser real (`api.ts`/`variantApi.ts` → `safeParse` → filtro) deben
     correr con la **respuesta real de la API** (campos nullable incluidos), NO solo mockear el
     hook completo. Si el componente mockea `useX`, igual debe existir un test del `getX` que
     parsea (ver `frontend/src/features/inventory-center/__tests__/variantApi.test.ts`).
   - Los schemas Zod de lectura DEBEN usar `z.string().nullable().optional()` en timestamps
     (o `z.coerce`) para que un `null` nunca descarte el recurso.

No se debe comenzar la implementación de una funcionalidad nueva sin tener primero sus tests
unitarios y de integración revisados y aprobados como contrato de comportamiento.

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

Para instalaciones locales de una sola computadora se usa SQLite como configuración predeterminada mediante
`.env.local-sqlite.example`. Usa WAL, `busy_timeout`, claves foraneas y
transacciones `IMMEDIATE`; no se debe compartir el archivo SQLite por red.
El perfil reproducible de pruebas es `phpunit.sqlite.xml`:

```bash
php vendor/bin/phpunit -c phpunit.sqlite.xml
```

**Separación de entornos:** local usa SQLite; el VPS de producción usa PostgreSQL 16 nativo.

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

### 10.3 Deploy al VPS nuevo (`212.28.176.157`)
```bash
ssh root@212.28.176.157   # password: GaboMac12 (NO usa SSH key, el viejo webadmin-vps está destruido)
cd /opt/inventarioarens-cloud
sudo /usr/bin/env git pull
sudo /usr/bin/env composer install --no-dev --optimize-autoloader
sudo /usr/bin/env php artisan optimize:clear
sudo /usr/bin/env php artisan migrate --force
```

**🚫 JAMÁS usar `php artisan view:cache`** — cachea Blade y los cambios no se ven hasta el próximo
`view:clear`. `optimize:clear` ya lo hace.

**🚫 JAMÁS reiniciar Traefik ni tocar `/root/deploy/core/traefik-config/`** — solo agregar
archivos nuevos (como `inventarioarens.yml` para nuestra route). Si hay que modificar un route
existente, confirmar primero con el usuario (afecta a otros productos).

### 10.4 Stack del VPS nuevo
- Nginx + PHP-FPM 8.4 + PostgreSQL 16 nativo (NO Docker) — INVENTARIOARENS.
- Traefik (Docker) en :80/:443 termina TLS y enruta a nuestro nginx local en `172.18.0.1:8080`.
- HTTPS con Let's Encrypt, cert en `/root/deploy/core/acme.json` de Traefik.
- DNS A: `app.miinventariofacil.com → 212.28.176.157`.
- Bootstrap: `scripts/cloud-api-bootstrap-vps.sh` (actualizado al host nuevo).
- Detalle completo: `docs/MIGRACION_VPS_2026-07-21.md`.

---

## 11. Diagnóstico rápido

| Síntoma | Causa probable | Fix |
|---|---|---|
| 401 en API tras deploy | Sesión cacheada con token viejo | `php artisan optimize:clear` |
| Sync local detenido | Servicio `SistemaInventarioSync` detenido o Motor ausente | Revisar el servicio y `storage/logs/services/sync`; no recrear tareas programadas |
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

**Telegram / Configuración por empresa**:
- `docs/TELEGRAM_BOT_MODULE.md` — bot de administración por empresa: webhook, lista blanca
  (`telegram_bot_users`), resumen diario, alertas de stock, `tenant_settings` y
  `GET/PATCH /api/tenant-settings`. Bot real `miinentariofaciladmin_bot` (token en `.env` del VPS).

**Laboratorios / testing automatizado**:
- `docs/LAB_DAY_SIMULADO.md` — día simulado: `php artisan lab:day` + `scripts/run-day-lab.ps1`
  ejecutan un ciclo real de negocio (login → POS → devolución → compra → traslado) contra local o
  VPS con datos desechables, junto a k6 (`run-stress-lab.ps1`), sync-e2e y Playwright.

**Impresión de tickets**:
- Módulo `Printing` (`app/Modules/Printing/`): perfiles 58/80mm, estaciones, jobs con snapshot.
  Agente local `php artisan printer:serve` en `127.0.0.1:17777` → driver Windows (`Out-Printer`)
  / Linux (`lpr`) o impresora de red TCP 9100 con ESC/POS (corte GS V, gaveta ESC p). Panel en
  `/printing` del frontend. Detalle en `docs/MODULES.md` y `docs/GRAPHIFY_CONTEXT_MAP.md`.
- Conector Cloud independiente (2026-08-25): `tools/print-connector/connector.cjs` hace polling
  HTTPS saliente con token propio, pairing de un solo uso, claim/ACK y reintentos por lease. No
  abre puertos ni depende del Motor Local. Las estaciones termicas vinculadas a un conector no se
  envian desde React al agente `:17777`; los tickets digitales se descargan desde el navegador.
  Contrato en `docs/PRINT_CONNECTOR.md`. Desde 2026-08-28 el instalable es un cliente Electron
  independiente (`tools/print-connector/main.cjs` + `renderer/`) con pairing, estado, bandeja y
  arranque automatico; el usuario no necesita ejecutar PowerShell. Su release usa
  `.github/workflows/release-print-connector.yml` y publica instalador NSIS + portable en
  `v<version>-connector`. El packager Node SEA y las tareas PowerShell anteriores quedan solo como
  artefactos de migracion y no se incluyen en la GUI.
- Guia de uso para usuarios finales: `docs/GUIA_USUARIO_CONECTOR_IMPRESION.md`.
- Prueba fisica con impresora real: `docs/GUIA_PRUEBA_IMPRESORA_REAL.md`.
- Consola `/support` controla el agente: `POST /api/local-support/printer/{action,test}`
  (instalar/iniciar/probar) + auto-arranque en Electron (`backend-runtime.cjs` → `printer:serve`).

**Infraestructura Electron (backend compartido)**:
- Desde 2026-08-14 el backend local y el agente de impresión pertenecen al instalador independiente
  **Motor Local - Sistema de Inventario**. Los clientes Administrativo, POS y Soporte Técnico son
  solamente interfaces y no pueden instalar, actualizar ni desinstalar PHP, Laravel o los servicios.
- Servicios Windows reales: `SistemaInventarioBackend` (`127.0.0.1:8787`),
  `SistemaInventarioPrinter` (`127.0.0.1:17777`) y `SistemaInventarioSync`, administrados por WinSW
  y configurados con inicio automático, reinicio ante fallo y logs rotativos.
- El Motor se instala por versiones en
  `%ProgramFiles%\Sistema de Inventario\Motor\versions\<version>`; conserva datos, tokens y SQLite en
  `%ProgramData%\InventarioArens` durante la primera fase de migración. Nunca reemplazar esa carpeta
  de datos al actualizar el Motor.
- Fuente canónica: `docs/MOTOR_LOCAL_WINDOWS_PLAN_2026-08-14.md`. Las auditorías anteriores sobre
  tareas programadas son históricas y no deben usarse para implementar releases nuevos.

**Auditoría backend 2026-07-11**:
- `docs/AUDIT_2026-07-11/00_RESUMEN_EJECUTIVO.md`
- `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` — contrato API para futuro frontend.
- `docs/AUDIT_2026-07-11/ROADMAP.md` — items P0-P4.
- `docs/AUDIT_2026-07-11/{01..10}_*.md` — auditorías detalle por módulo.

**Frontend nuevo (en construcción desde 2026-07-13)**:
- Stack acordado: **Vite + React 18 + TS + TanStack (Query/Router/Table) + Tailwind 4 + Radix UI + Zustand**.
- Vive en `frontend/` dentro de este repo (no en repo separado).
- **Diseño completo** en:
  - `docs/FRONTEND_ARQUITECTURA.md` — stack, estructura, patrones, deploy.
  - `docs/FRONTEND_PERMISSIONS.md` — sistema de permisos (3 niveles + scopes + field masking).
  - `docs/FRONTEND_FASES.md` — roadmap por fases (Fase 0 setup → Fase 7 reportes).
  - `frontend/README.md` — setup y comandos.
- Debe consumir los endpoints documentados en `docs/API.md` y respetar
  `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` + `docs/INSTRUCCIONES_FRONTEND_*.md`.
- Para continuar la validacion de los clientes Electron en Windows, la fuente de verdad operativa es
  `docs/WINDOWS_ELECTRON_VALIDATION.md`, especialmente su seccion `Handoff para IA en Windows`.
  El supervisor compartido vive en `frontend/electron/backend-runtime.cjs`; el arranque de cliente y
  modo supervisor viven en `frontend/electron/main.cjs`. No validar NSIS tocando el VPS ni duplicando
  `php artisan serve` por ventana.

---

## 13. Comandos rápidos de referencia

```bash
# Backend
composer install
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=MultiCompanyLoginDemoSeeder --force
php artisan db:seed --class=DemoDataSeeder --force
php artisan dev:reset-demo-passwords            # gabo1234 (default) para TODOS los demo users
php artisan optimize:clear
php artisan route:clear
php artisan config:cache
```

```bash
# Frontend / Electron (desde frontend/)
pnpm install
pnpm test
pnpm run dev:admin
pnpm run dev:pos
pnpm run electron:build:admin   # Linux AppImage; Windows NSIS en CI Windows
pnpm run electron:build:pos
pnpm run electron:smoke:linux:admin
pnpm run electron:smoke:linux:pos
```

### Demo users (login dev)

El `DemoDataSeeder` actual crea estos usuarios para las empresas demo:

| Email | Tenant | Password |
|---|---|---|
| `gerente.caracas@demo.test` | `demo-caracas` | `password` |
| `cajero.caracas@demo.test` | `demo-caracas` | `password` |
| `gerente.valencia@demo.test` | `demo-valencia` | `password` |
| `cajero.valencia@demo.test` | `demo-valencia` | `password` |

Para cargar estos datos: `php artisan db:seed --class=DemoDataSeeder --force`.

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

# VPS nuevo (212.28.176.157, password GaboMac12 — NO usa key)
ssh root@212.28.176.157
ssh root@212.28.176.157 "sudo -u postgres psql -d inventory_arens -c 'SELECT 1'"

# Diagnóstico local
Test-NetConnection 212.28.176.157 -Port 5432
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
- ❌ Cambiar el stack del frontend (`docs/FRONTEND_ARQUITECTURA.md` §2) sin abrir conversación previa.
  El stack Vite + React 18 + TS + TanStack + Tailwind 4 + Radix UI + Zustand está acordado el
  2026-07-13. Cambios requieren re-evaluación.
- ❌ Implementar features del frontend sin tests asociados (ver §9.4). Fase 1 incluye tests E2E
  obligatorios de login + inventario.

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
8. **Frontend SPA + Electron desde 2026-07-13** → actualizar `docs/FRONTEND_FASES.md` cambiando
   ☐ → 🔄 → ✅ al avanzar, y `docs/IMPLEMENTATION_LOG.md` con cada entrega.
9. **Updates/workers/sync de escritorio (2026-08-08)** → documentar en
   `docs/ELECTRON_UPDATES_AND_TECHNICIAN.md` y reflejar en `docs/GRAPHIFY_CONTEXT_MAP.md` antes de
   tocar el release workflow, las tareas programadas de Windows o la propagación del catálogo.

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

## Sprint POS performance/UX (2026-07-21)

Quick wins para bajar la latencia del POS y corregir la desincronización de stock. Cubre 4 puntos del roadmap acordado el 2026-07-20.

**QW1 — Precio base vs lista default** (`app/Modules/Products/Services/ProductPriceService.php`): antes `price_list_id=null` se interpretaba como lista predeterminada. Ahora existe `price_source ∈ {base, list}` para distinguir precio base explícito de lista. `SaleService::createDraft` propaga el campo y `PosCheckoutService::priceListsForItems` lo respeta para que las líneas en base no queden atrapadas por restricciones de método de pago.

**QW2 — Sync POS replica stock e IMEI vendido** (`app/Modules/Sync/Services/SyncEventApplier.php` + `app/Modules/POS/Services/PosCheckoutService.php`): cuando se replica `pos.order.paid` con `sale_status=confirmed`, el applier decrementa `stock_balances` en la nube y marca `product_units.status=sold` por identidad natural `(serial_type, serial_number)`. El outbox ahora incluye `product_serial_units` por línea (no solo IDs autoincrementales, que no son identidad válida entre nodos). Test dedicado: `tests/Feature/Sync/PosOrderStockSyncTest.php` cubre quantity tracking, IMEI tracking y pendiente sin movimiento.

**QW3 — Endpoint `/api/pos/bootstrap`** (`app/Modules/POS/Controllers/PosBootstrapController.php`): devuelve en una sola request `warehouses`, `cash_registers` activas, `payment_methods` activos, `price_lists` activas (con flag default), `exchange_rate_types`, `exchange_rates` y la sesión de caja abierta del cajero autenticado (sin movimientos). Reduce el arranque del POS de ~10 requests a 1. Test: `tests/Feature/POS/PosBootstrapApiTest.php`.

**QW4 — Paginación server-side y recursos resumidos** (`app/Modules/POS/Controllers/PosOrderController.php` + branches/warehouses):
- `PosOrderController::index` lee `per_page` (1-100), soporta `summary=1` para listar pendientes con `PosOrderSummaryResource` (sin sale completo, sin catálogos pesados), filtra por `cash_register_session_id`, `cashier_id`, `customer_id`, `date_from`, `date_to`, `search`.
- `WarehouseController` y `BranchController` aceptan `per_page` (con fallback `limit`).
- Eager load corregido: `customer`, `sale`, `sale.items`, `payments.paymentMethod:id,name` (sin `cashRegisterSession` que no se serializaba).

**Verificación**: `phpunit` 51/51 verde en POS + Sync POS + bootstrap + transfer requests; Pint aplicado a los archivos modificados; `tsc --noEmit` limpio.

## Fix solicitudes inter-empresa (2026-07-20)

Bug critico en `/api/inventory-transfer-requests/{id}/accept`: el flujo estaba invertido respecto a la operacion real. Antes la empresa que aceptaba rebajaba el stock de su propia empresa (`destination_tenant_id`) para dejarlo en la solicitante (`origin_tenant_id`). Ahora la empresa que acepta es la que SUMINISTRA el stock: descuenta unidades de SU almacen y SU stock serializado, y la solicitante los recibe en el almacen que eligio al crear la solicitud.

Cambios principales:

**Backend** (`app/Modules/InventoryTransferRequests/`):
- `Services/InventoryTransferRequestService.php`: nuevo helper `resolveRespondingUnits()` que valida estrictamente que `count(serial_units) == quantity`, que todos los IMEIs/seriales existan y esten disponibles en el almacen seleccionado, y rechaza duplicados. Renombrados `removeRespondingUnits` y `createRequesterUnits` para reflejar el nuevo flujo. Las excepciones se traducen a `ValidationException` con mensajes en espanol.
- `Requests/AcceptInventoryTransferRequestRequest.php`: agregada regla `items.*.serial_units.* = array` para que Laravel no filtre silenciosamente los items serializados.
- Docblock actualizado para usar vocabulario `requester`/`responding`.

**Sync** (`app/Modules/Sync/Services/SyncEventApplier.php`):
- `applyInventoryTransferRequestAccepted` y `applyTransferRequestItemAccepted` ahora restan stock en `destination_tenant_id` (empresa que responde) y suman en `origin_tenant_id` (solicitante), replicando el fix local.
- `createCloudProductExit` recibe `serial_units` y resuelve las `product_unit_ids` por `(serial_type, serial_number)` en el almacen de salida; falla con `RuntimeException` si faltan unidades disponibles.

**Documentacion**:
- `AGENTS.md §4`: nota explicita de que `destination_tenant_id` es la empresa que RESPONDE y SUMINISTRA stock, y `origin_tenant_id` la empresa que SOLICITA y RECIBE stock.
- `docs/ARCHITECTURE.md` y `docs/API.md`: descripcion del flujo corregido (transfer_request_out en la que responde, transfer_request_in en la solicitante).

**Tests**:
- `tests/Feature/InventoryTransferRequests/InventoryTransferRequestApiTest.php`: nuevo caso `test_requester_without_stock_can_receive_serialized_units_from_responding_company` que cubre el bug original (solicitante sin stock, empresa proveedora envia IMEIs). Caso adicional `test_accept_rejects_unavailable_imei_without_moving_stock` para garantizar rollback transaccional. Tests existentes actualizados para reflejar la nueva direccion del stock.
- `tests/Feature/Sync/InventoryTransferRequestSyncTest.php`: el test `test_accepted_event_syncs_stock_movement_to_both_tenants` ahora usa IMEIs y verifica que el sync replica correctamente la salida en la que responde y la entrada en la solicitante.

## Setup de sync (operador) [2026-07-14]

### One-liner para emitir token de sync

En el VPS, el comando \php artisan sync:ensure-and-token <tenant-slug>\ hace TODO:

- Crea el tenant (si no existe).
- Crea el user (default: \gabo@gabo.com\, is_platform_admin=true).
- Vincula user <-> tenant (status=active).
- Crea el SyncNode.
- **Revoca** cualquier token valido anterior para esa combinacion (rotacion OAuth-style).
- **Emite uno nuevo** (365 dias por default) y lo imprime en stdout como \TOKEN=xxxxx\.

Ejemplos:

\\\ash
# En el VPS:
php artisan sync:ensure-and-token mi-empresa
php artisan sync:ensure-and-token grupo-prueba --user=admin@local --node-name=POS-01
\\\

### Wrapper local (Windows) - automatizacion

El script \scripts/sync_token.py\ automatiza la obtencion del token sin tener que entrar al VPS a cada rato.

Uso:

\\\powershell
cd C:\\Users\\gafit\\Documents\\INVENTARIOARENS
python scripts/sync_token.py <tenant-slug>            # SSH + emit + update .env
python scripts/sync_token.py <tenant-slug> --print    # solo imprime, no toca .env
python scripts/sync_token.py <tenant-slug> --run      # emite + .env + ejecuta sync:run local
python scripts/sync_token.py <tenant-slug> --user <email>     # user custom
python scripts/sync_token.py <tenant-slug> --node-name <name> # node name custom
\\\

El wrapper:
1. SSH al VPS (paramiko con password).
2. Ejecuta \php artisan sync:ensure-and-token\ y parsea el \TOKEN=...\ del output.
3. Actualiza \SYNC_CLOUD_URL\ y \SYNC_CLOUD_TOKEN\ en el \.env\ local.
4. Opcionalmente corre \php artisan sync:run <slug>\ en el local.

### Worker daemon en el VPS (configurado 2026-07-14)

Systemd timer \inventarioarens-sync.timer\ activo, corre cada 15 segundos y
llama a \php artisan sync:apply-all-inboxes --limit=200\. Procesa los inbox
de TODOS los tenants activos.

\\\ash
ssh root@212.28.176.157 \"tail -f /var/log/inventarioarens-sync.log\"
\\\

## Limitacion arquitectural: token por tenant

El sync actual **no soporta 1 token para multiples tenants** porque el middleware
\	enant\ exige \	oken->tenant_id === tenant->id\. Esto es por seguridad:
si un token de tenant A filtra, no puede usarse para escribir en tenant B.

Para multi-tenant se necesitaria un token de plataforma (is_platform_admin)
o refactor de las rutas de sync para saltar el middleware \	enant\. No
recomendado para produccion.

### Implementacion multiempresa local (2026-07-26)

La limitacion de token por tenant se mantiene por seguridad: una instalacion
local multiempresa usa un token y un worker independiente por cada empresa.
`local:configure-sync-tenants` escribe `storage/app/sync-worker/sync-config.json`
y el servicio `SistemaInventarioSync` supervisa todas las empresas con
`php artisan sync:daemon-all`. Cada empresa conserva token, nodo y ciclo independientes aunque
compartan el proceso supervisor. No usar un token de plataforma para sustituir este aislamiento.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

When the user types `/graphify`, use the installed graphify skill or instructions before doing anything else.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- Dirty graphify-out/ files are expected after hooks or incremental updates; dirty graph files are not a reason to skip graphify. Only skip graphify if the task is about stale or incorrect graph output, or the user explicitly says not to use it.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## Electron updates, technician client y Motor Local (actualizado 2026-08-14)

The Electron desktop clients use `electron-updater` with GitHub Releases as the initial provider.
Each client has an independent update channel and artifact configuration:

- `admin`: `Sistema-de-Inventario-Administrativo`
- `pos`: `Sistema-de-Inventario-POS`
- `technician`: `Soporte-Tecnico-Inventario-Arens`

Cada cliente se instala en su propia carpeta (`oneClick: false` + `executableName`), para que no
colisionen en `inventarioarens-frontend` ni se pisen el `app.asar`.

Los tres clientes son UI-only. No agregar `backend/`, PHP portable ni scripts de servicios a sus
`extraResources`. El runtime compartido se publica por separado mediante
`.github/workflows/release-motor.yml` y `installer/windows/MotorLocal.iss`.

### Releases automáticos

`.github/workflows/release.yml` construye y publica un cliente con `gh workflow run release.yml -f
client=pos` (o admin/technician). Construye en `windows-latest`, y publica con
`gh release create v<version>-<client>` (no-draft) subiendo el `.exe`, `.blockmap` y `<channel>.yml`
(`pos.yml`, `admin.yml`, `technician.yml`). El tag es `v<version>-<client>` para que los tres
clientes compartan el mismo `package.json` sin colisionar.

`.github/workflows/release-motor.yml` construye el instalador independiente del Motor. Una versión
de cliente no obliga a publicar otra versión del Motor; si cambia Laravel, PHP, impresión, sync o
la infraestructura local, se publica primero y se valida el Motor correspondiente.

**Regla de build**: siempre regenerar el bundle antes de empaquetar
(`pnpm run build:<client>` y luego `electron-builder`). Empaquetar sin regenerar `dist` publica un
bundle viejo y la UI nueva no llega.
Cada `electron-builder.<client>.yml` debe incluir solamente `dist/<client>`; no empaquetar los
bundles renderer de los otros clientes.

### Auto-update y workers

- El updater descarga en background y pregunta antes de reiniciar. El check corre al arrancar y cada
  **1 minuto** (`UPDATE_CHECK_INTERVAL_MS` en `frontend/electron/auto-updater.cjs`) — corto para
  verificar cambios rápido durante desarrollo.
- La sincronización de escritorio corre en el servicio Windows **`SistemaInventarioSync`** del Motor
  Local, independiente de las aplicaciones abiertas. Un solo supervisor procesa todas las empresas;
  Electron no crea tareas ni lanza daemons.
- La propagación del catálogo del grupo a las hijas emite eventos `sync_outbox` por cada spinoff;
  el applier local matchea productos por `sku`/`catalog_product_id`.
- El technician reutiliza la consola `/support` para vincular empresas, ver estado de sync y
  diagnosticar. LAN server mode queda loopback por defecto; SQLite nunca se comparte por red.

Detalle completo en `docs/ELECTRON_UPDATES_AND_TECHNICIAN.md`.

## Instalacion aislada Tiendas Arens (2026-08-17)

Tiendas Arens es un segundo cliente desplegado en el mismo VPS, pero con aislamiento de aplicacion
y base de datos respecto a MiInventarioFacil. Su dominio es `app.tiendasarens.com`, su Laravel vive
en `/opt/tiendasarens-cloud`, usa la base PostgreSQL `inventory_tiendasarens`, el usuario
`tiendasarens_app`, PHP-FPM pool/socket propios y Nginx en el puerto interno `8082`. La instalacion
existente conserva `/opt/inventarioarens-cloud`, `inventory_arens` y el puerto `8080`.

Los workers `tiendasarens-queue.service`, `tiendasarens-sync.service` y
`tiendasarens-sync.timer` son independientes. La ruta Traefik se agrega como archivo nuevo en
`/root/deploy/core/traefik-config/`; no tocar routers de otros productos ni reiniciar Traefik para
cambios ordinarios. La landing `tiendasarens.com` y sus registros de correo se mantienen fuera de
este stack. El procedimiento operativo completo esta en `docs/DEPLOY_TIENDAS_ARENS_VPS.md`.

## Traslados v2 � Fase 0 (2026-07-19)

Cambios criticos para que el flujo de IMEIs/seriales funcione end-to-end:

**Backend** (`app/Modules/InventoryTransfers/`):
- `Services/InventoryTransferService.php`: nuevo helper `resolveSerialUnits()` que toma `{serial_type, serial_number}[]` del payload y los traduce a `ProductUnit` IDs (busca existente o crea AVAILABLE). Se invoca en `validateItems()` antes de validar `product_unit_ids`. Helper `resolvePayloadSerialUnits()` similar para `prepare()` y `receive()`.
- `Requests/StoreInventoryTransferRequest.php`: agregadas reglas `items.*.serial_units`, `items.*.serial_units.*.serial_type`, `items.*.serial_units.*.serial_number` (sin esto, Laravel filtraba silenciosamente el array y el backend rechazaba con `Debe indicar una unidad serializada`).

**Frontend** (`frontend/src/features/transfers/`):
- `schemas.ts`: tipos existentes (no cambia el shape).
- `components/TransferCreateDialog.tsx`: ya no envia IDs falsos para serial_units (cambio a `serial_units: [...]` array).
- `components/TransferPrepareDialog.tsx`: reescrito completo. UI con scanner de IMEI: input + boton Agregar + lista de IMEIs/seriales ingresados + boton Quitar. Submit envia `prepared_serial_units` al backend.
- `components/TransferReceiveDialog.tsx`: idem para recepcion.
- `components/TransferChecklistTab.tsx`: envia `checked_product_unit_ids: item.expected_product_unit_ids` cuando el user marca el item como completo (antes enviaba array vacio, lo que impedia que el checklist marcara como completado).

**Tests backend**: `tests/Feature/InventoryTransfers/InventoryTransfersSerialUnitsTest.php` (nuevo). 2 tests skipped (TODO Fase 1: el cache de permissions de Spatie filtra las ProductUnits creadas via DB::table en tests sin tenant context seteado).

**Cleanup**:
- Eliminado `TYPE_INTER_COMPANY` del modelo `InventoryTransfer` (dead code).
- Eliminado workaround `Route::bind('inventoryTransferGuide', ...)` en routes.php. Ahora se usa el parametro estandar `inventoryTransfer` que Laravel resuelve via implicit binding.
- Agregado multi-tenancy check en `InventoryTransferGuideController::authorizeAccess()` (antes solo validaba status, ahora valida tenant_id del transfer contra el tenant activo).
- Fix copy "CxP" en `frontend/src/routes/_authed/transfers.tsx` (linea 40): ahora dice "...preparar -> despachar -> recibir -> resuelto".

**Fix de UI**:
- `InventoryTransferResource` ahora expone `items_count`, `total_base_amount`, `total_local_amount`, `received_base_amount`, `received_local_amount` (antes retornaba null ? UI mostraba $0).
- Frontend schema `TransferItemSchema` removio `warehouse_id`/`warehouse` (el backend NO expone esos campos del modelo; estaban en el schema pero siempre eran undefined ? mostraba `Almacen #undefined`).

## Traslados v2 � Fase 1 (proxima): IMEI scanner real

Pendiente para Fase 1 (orden de las tareas):
1. Backend: `GET /api/inventory-centers/products/{product_id}/units?status=available&warehouse_id={wid}&search={q}` para listar ProductUnits disponibles del almacen de origen.
2. Frontend: componente `<ImeiScanner>` reutilizable con la lista de IMEIs disponibles + boton de toggle.
3. Integrar scanner en los 3 dialogs (Create, Prepare, Receive) en lugar del input de texto libre actual.
4. Resolver el test skipped (cache de permissions en tests con DB::table).

## Traslados v2 � Fase 2 (planificada)

- Backend: timeline endpoint + UI de timeline en el detalle del traslado.
- Backend: dialog de resolver diferencias (UI para `useResolveTransferDifferences()`).
- Frontend: filtros visibles en bandeja (from_warehouse_id, to_warehouse_id, date_from, date_to).
- Frontend: paginacion con `meta` del backend.

## Traslados v2 � Fase 3 (planificada, modulo inter-empresa)

Frontend completo de inventory-transfer-requests (la bandeja con tabs Enviadas/Recibidas/Pendientes/Completadas/Rechazadas, dialogs de crear/aceptar/rechazar). Backend ya esta completo desde antes.

## Fix deteccion del appMode en Electron (2026-08-06)

Bug critico: el NSIS instalaba ambos clientes (`Sistema-de-Inventario-Administrativo.exe` y `POS.exe`) en la misma carpeta `%LOCALAPPDATA%\Programs\inventarioarens-frontend\`, asi que compartian el mismo `resources/app.asar`. Al abrir el Administrativo, Electron cargaba el `app.asar` del POS y la ventana decia "POS". El campo `inventarioAppMode` del `frontend/package.json` (un custom field) **no es sobrescrito por `extraMetadata` en electron-builder**, asi que ambos builds embebian el mismo `package.json` con `mode: pos` aunque el yml admin dijera `mode: admin`.

Cambios:

- `frontend/electron/app-mode.cjs` (nuevo): `detectAppMode({ execPath, env })` deriva el modo del `path.basename(process.execPath)`. Match por substring case-insensitive: `pos` -> 'pos', `administrativo` -> 'admin'. La env var `INVENTARIO_APP_MODE` gana sobre el exe name (util para `electron:dev:*`).
- `frontend/electron/app-mode.test.js` (nuevo): 9 tests cubren env var vs basename, linux paths, case-insensitive, fallback admin.
- `frontend/electron/main.cjs`: usa `detectAppMode()` en vez de leer `package.json` del asar.
- `frontend/electron/app-config.cjs` + `app-config.test.js`: `pos.productName` ahora es `Sistema de Inventario (POS)` (antes `'POS'` que era generico y colisionaba con busquedas de `tasklist /FI "IMAGENAME eq POS*"`).
- `frontend/electron-builder.pos.yml`: agrega `executableName: Sistema-de-Inventario-POS`, `productName: Sistema de Inventario (POS)`, `shortcutName` y `uninstallDisplayName` especi­ficos. Remueve `extraMetadata.inventarioAppMode` (ya no se usa).
- `frontend/electron-builder.admin.yml`: solo remueve `extraMetadata.inventarioAppMode` por consistencia.
- `frontend/build/nsis/separate-install-dir.nsh` (nuevo): hook `customInstall` que fuerza `$INSTDIR = $LOCALAPPDATA\Programs\${PRODUCT_FILENAME}`. Asi Administrativo y POS se instalan en carpetas separadas y no se pisan los `resources/app.asar`.
- Ambos `electron-builder.*.yml` referencian el `.nsh` via `nsis.include: build/nsis/separate-install-dir.nsh`.

**Reglas operativas para los builds Electron (sustituye el item 13.2 de `docs/WINDOWS_ELECTRON_VALIDATION.md`)**:

- Cada cliente tiene un `executableName` y `productName` unico. NO hardcodear nombres genericos (ej: `'POS'`) porque colisionan con binarios del sistema (PostgreSQL `postgres.exe`, impresoras de tickets, etc.).
- El modo del cliente se detecta del nombre del `.exe` (substring `pos` o `administrativo`), no del `package.json` del asar (que es compartido entre builds y `extraMetadata` no sobrescribe custom fields).
- Cada build NSIS debe instalar en su propia carpeta (`build/nsis/separate-install-dir.nsh`). NO usar `appId` similares que apunten al mismo `name` de `package.json` (`inventarioarens-frontend` se reusaba y causaba que ambos .exe aterrizaran en la misma carpeta).

**Al desinstalar/reinstalar manualmente los clientes (workaround temporal mientras se regeneran los builds)**:

```powershell
# 1. Cerrar todos los .exe y matar php.exe supervisor
Get-Process -Name "php","php-cgi","POS","Sistema-de-Inventario-Administrativo" -ErrorAction SilentlyContinue | Stop-Process -Force

# 2. Borrar el lock del supervisor
Remove-Item "$env:APPDATA\InventarioArens\.runtime-supervisor.lock","$env:APPDATA\InventarioArens\.runtime-supervisor.pid" -Force -ErrorAction SilentlyContinue
Remove-Item "$env:APPDATA\InventarioArens\runtime-leases\*.lease" -Force -ErrorAction SilentlyContinue

# 3. Desinstalar versiones previas
$root = "$env:LOCALAPPDATA\Programs"
if (Test-Path "$root\inventarioarens-frontend") { & "$root\inventarioarens-frontend\Uninstall Sistema de Inventario (Administrativo).exe" }
if (Test-Path "$root\inventarioarens-frontend") { & "$root\inventarioarens-frontend\Uninstall POS.exe" }
# (los .exe del nuevo install tendran nombres segun su productName)

# 4. Reconstruir con los ymls actualizados
cd frontend
pnpm run electron:build:admin   # en este orden primero admin
pnpm run electron:build:pos

# 5. Instalar en este orden
& "frontend\release\admin\Sistema-de-Inventario-Administrativo-0.1.0.exe"
& "frontend\release\pos\Sistema-de-Inventario-POS-0.1.0.exe"
```

## Hardening de inventario e idempotencia (2026-08-24)

- Las reservas de inventario guardan `stock_movements.reservation_expires_at` (TTL por defecto de
  30 minutos). `php artisan inventory:expire-reservations` libera saldo y ProductUnits reservadas
  cuyo movimiento vencio; la tarea corre cada 5 minutos y es idempotente por movimiento original.
- `php artisan inventory:reconcile` ahora detecta tambien drift entre `stock_balances` y
  ProductUnits serializadas/IMEI. `--fix-serials` corrige esos saldos explicitamente; no usarlo sin
  revisar el reporte cuando el ledger y las unidades difieren.
- Sync replica `reservation_expires_at` y aplica `reserved`/`released` a las cantidades disponible y
  reservada. Los eventos de ProductUnit conservan la identidad natural serializada.
- Se agrego middleware `idempotency` a las mutaciones criticas de ventas, compras, entradas/salidas,
  devoluciones, solicitudes interempresa, caja, CxP, recibos y ajustes financieros. El header sigue
  siendo opcional; sin `Idempotency-Key` el flujo conserva el comportamiento anterior.
- La base de PHPUnit esta separada de produccion: `phpunit.xml` usa `inventory_arens_testing_local`;
  el perfil SQLite reproducible es `phpunit.sqlite.xml`. No usar `inventory_arens` para tests.
- Estos cambios no publican ni construyen Electron.

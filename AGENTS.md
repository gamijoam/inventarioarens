# AGENTS.md — Guía persistente para opencode en INVENTARIOARENS

> Este archivo es leído por opencode (y por otros agentes IA que respeten el estándar `AGENTS.md`) al
> inicio de cada sesión. Si algo en el proyecto cambia de forma estructural, **actualizarlo aquí primero**.

---

## 1. Qué es este proyecto

**INVENTARIOARENS** es un SaaS modular **multi-tenant** de gestión de inventario + punto de venta,
escrito en **Laravel 13 / PHP 8.3-8.4 / PostgreSQL**. Tiene tres superficies de cliente:

| Cliente | Stack | Función |
|---|---|---|
| **WPF Escritorio** (`desktop/InventoryDesktop/`) | C# .NET 8 + MVVM manual + DPAPI + MaterialDesignThemes 5.2.1 | Operación: POS, caja, traslados, inventario, clientes, sync |
| **WPF Configurador** (`desktop/InventorySyncInstaller/`) | C# .NET 8 standalone | Bootstrap inicial de PC: valida requisitos, crea DB, registra tenant, instala worker |
| **Portal Web Admin** (`/admin` → `resources/views/admin.blade.php`) | Blade + JS vanilla + Tailwind 4 + Vite 8 | Gerencia: productos, precios, usuarios, roles, reportes, CxC/CxP, traslados (drawer), compras |

**Contexto de mercado (Venezuelano)**: moneda base **USD**, operativa **VES**, con tipos de tasa
(`BCV`, `PARALELO`, tienda) y snapshot de rate en cada movimiento monetario.

**Infraestructura real**:
- Local: Windows + Laragon + PHP 8.4.23 (`C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe`)
- Local DB: PostgreSQL 16 en `127.0.0.1:5434`, DB `inventory_arens`, user `inventory_arens`/`secret`
- VPS nube: `217.216.80.158` (Contabo Ubuntu 24.04), Nginx + PHP-FPM en `/opt/inventarioarens-cloud/public`
- DB nube: PostgreSQL 16 nativo (NO Docker) en `127.0.0.1:5432`, DB `inventory_arens`, user `postgres`
- Dominio público: **`https://app.miinventariofacil.com/api`** (HTTPS Let's Encrypt)
- SSH key para VPS: `C:\Users\gafit\.ssh\webadmin-vps` (user `webadmin`)

---

## 2. ⚠️ REGLA CRÍTICA — NO CONFUNDIR con MiInventarioFácil

El usuario tiene **DOS productos SaaS** que comparten marca pero viven en VPSs distintos con stacks
distintos. Esto ya causó errores graves en sesiones previas:

| Proyecto | VPS | Backend | DBs | SSH key |
|---|---|---|---|---|
| **INVENTARIOARENS (ESTE)** | `217.216.80.158` | Laravel 13 | `inventory_arens` | `webadmin-vps` |
| MiInventarioFácil (OTRO) | `212.28.176.157` | FastAPI 2.2.0 | `invensoft_qa` / `invensoft_prod` | `bloqueo_vps_mavis` |

Antes de tocar la nube, **confirmar siempre**:
1. SSH al VPS correcto: `ssh -i webadmin-vps webadmin@217.216.80.158`
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
│   ├── Modules/                          ← 34 módulos con MVC propio cada uno.
│   ├── Providers/                        ← AppServiceProvider + TenancyServiceProvider.
│   └── Support/
│       ├── Permissions/BasePermissions.php          ← Catálogo de 95 permisos + 5 roles.
│       ├── Performance/PerformanceProbe.php          ← Métricas PERF OK/LENTO BACKEND.
│       └── Tenancy/                                   ← TenantManager, TenantScope, BelongsToTenant trait.
├── bootstrap/
│   ├── app.php                            ← Middleware aliases 'api.auth' + 'tenant', comandos.
│   └── providers.php
├── config/                                ← app, auth, cache, database, filesystems, queue, session, services.
├── database/
│   ├── migrations/                        ← 72 migraciones (cronología 2026-07-02 → 2026-07-10).
│   ├── seeders/{DatabaseSeeder,RolesAndPermissionsSeeder,DemoDataSeeder,MultiCompanyLoginDemoSeeder}.php
│   └── factories/UserFactory.php
├── desktop/
│   ├── InventoryDesktop/                  ← App WPF principal (MVVM manual).
│   ├── InventorySyncInstaller/            ← Configurador WPF standalone.
│   └── InventoryDesktop.slnx
├── docs/                                  ← ~70 .md de diseño, implementación e historia.
├── resources/
│   ├── views/{welcome,admin}.blade.php    ← Solo 2 vistas. Todo lo demás es JS que peg?
│   ├── js/{app,admin}.js                  ← Frontend vanilla JS (~338KB admin).
│   └── css/admin.css                      ← Estilos alta densidad.
├── routes/
│   ├── web.php                            ← Solo `GET /` y `GET /admin`.
│   ├── api.php                            ← Thin aggregator; carga routes.php de cada módulo bajo 'api.auth'+'tenant'.
│   └── console.php
├── scripts/                               ← PowerShell/VBS del sync worker + bootstrap VPS.
├── tests/                                 ← ~390 tests Feature con --process-isolation.
├── storage/app/sync-worker/               ← (generado) sync-config.json por empresa.
├── public/build/                          ← NO en git. Regenerar con `pnpm run build`.
├── .harness/                              ← DE OTRO AGENTE. NO TOCAR sin OK del usuario.
├── .agents/                               ← VACÍA. Eliminable si se limpia.
├── .codex/                                ← DE OTRO AGENTE. Solo temp_sync_probe.php. NO TOCAR sin OK.
├── .githooks/pre-push                     ← Hook de tests pre-push. NO TOCAR.
├── .github/workflows/ci.yml               ← CI: phpunit + playwright jobs.
├── docker-compose.yml                     ← Stack dev local (app, app_test, postgres 17).
├── BUILD.md, composer.json, package.json, phpunit.xml, vite.config.js, README.md
└── AGENTS.md                              ← ESTE ARCHIVO.
```

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
  - Token de usuario: `POST /api/auth/login`, expiración 30 días, WPF lo guarda con DPAPI en `TokenVault.cs`.
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

Compartida por WPF y Web (mismo backend):

1. `POST /api/auth/tenants {email}` (sin auth) → lista de tenants donde el user está `active`.
2. Usuario selecciona empresa → `POST /api/auth/login` con `X-Tenant: <slug>` + password →
   devuelve Bearer token + user + tenant + roles + permisos efectivos.
3. Cada llamada: `Authorization: Bearer <token>` + `X-Tenant: <slug>`.

WPF guarda el token en `Core/Security/TokenVault.cs` (DPAPI del Windows user actual).

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
| Vite | 8.0 (requiere Node 20.19+ o 22.12+) |
| Tailwind | 4.0 (vía `@tailwindcss/vite`) |
| Playwright | 1.61 |
| MaterialDesignThemes | 5.2.1 (WPF login redesign 2026-07-12 + tenancy 3 niveles UI 2026-07-13) |
| desktop | C# .NET 8 WPF (`net8.0-windows`) + MaterialDesignThemes 5.2.1 |
| Composer scripts | `composer setup`, `composer dev`, `composer test` |
| pnpm scripts | `pnpm run build`, `pnpm run dev`, `pnpm e2e`, `pnpm e2e:install` |

**Importante**: NO usar `Alpine.js` ni `Livewire` — el frontend es **JS vanilla + Blade + Tailwind**.

---

## 7.1 Disciplina obligatoria tras cambios de XAML (WPF)

**Regla innegociable** (introducida el 2026-07-11 tras el incidente del `-140` en `HorizontalAlignment`
que crasheaba la app en runtime pero pasaba el build):

1. **Después de editar cualquier archivo `.xaml` bajo `desktop/InventoryDesktop/`** se debe correr el
   smoke test XAML **antes** de dar la tarea por terminada:

   ```bash
   dotnet run --project desktop/InventoryDesktop.XamlSmoke/InventoryDesktop.XamlSmoke.csproj
   ```

2. **El smoke test detecta** (entre otros):
   - Valores enum inválidos en propiedades (`HorizontalAlignment="-140"`, `Visibility="Foo"`, etc.).
   - Tipos desconocidos o mal escritos.
   - Attached properties mal aplicadas.
   - Sintaxis XAML rota.

3. **El smoke test NO detecta** (por diseño — son limitaciones del parser sin code-behind cargado):
   - Handlers `Click="X_Click"` que no existen en el code-behind. Para esos, abrir la app con
     `dotnet run --project desktop/InventoryDesktop` y disparar la acción manualmente.
   - `StaticResource` no encontrados (App.xaml no se carga en el smoke test).

4. **Antes de reportar como listo**, el log del smoke test debe mostrar `Fallos reales: 0`.
   Warnings de recursos son aceptables.

5. Si el smoke test NO pasa, **NO marcar el cambio como completado** ni commitear.

**¿Por qué no basta con `dotnet build`?** Porque el build solo compila XAML en BAML cuando hay code-behind
referenciado por `x:Class`. Validaciones runtime (parseo de propiedades) corren cuando WPF instancia
los controles, momento en el cual la app crashea con `XamlParseException`. El smoke test invoca el
parser XAML estáticamente sobre cada `.xaml` con `x:Class` y atributos de event handlers stripped,
lo que reproduce exactamente esos crashes sin necesidad de arrancar la GUI.

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
- `routes/web.php` es MÍNIMO (solo `/` y `/admin`). No agregar rutas de negocio acá.
- `routes/api.php` es un aggregator; cada módulo declara su `routes.php` y se carga acá.
- Todas las rutas de API van bajo los middlewares `['api.auth', 'tenant']` excepto `auth/*` público.
- Todas las rutas están prefijadas con `/api` automáticamente.

### 8.3 Modelos
- Modelos nuevos de negocio **deben** `use BelongsToTenant`.
- Constantes en MAYÚSCULAS con `_` (e.g. `STATUS_ACTIVE`, `TRACKING_SERIALIZED`).
- Llaves foráneas compuestas con `tenant_id` cuando la tabla padre es tenant-scoped.

### 8.4 Permisos (95 totales)
- Catálogo maestro: `App\Support\Permissions\BasePermissions::PERMISSIONS`.
- 5 roles predefinidos: `Owner`, `Administrador` (todos los perms), `Gerente` (casi todos excepto
  `users.create/update/delete`, `roles.*`, `settings.manage`, `ai.configure`), `Vendedor`
  (sales/POS/caja), `Almacen` (inventario/traslados/compras), `Auditor` (read-only).
- Cada permission se nombra como `<modulo>.<verbo>` (e.g. `inventory_transfers.prepare`,
  `pos.checkout`, `cash_register.open`).
- Cada policy/método importante valida con `Gate::authorize` o `$request->user()->can(...)`.

### 8.5 Dinero
- **Doble cuenta** en cada movimiento monetario: `*_base_amount` (USD) + `*_local_amount` (VES).
- Snapshot del rate: `exchange_rate_type_id`, `exchange_rate_type_code`, `exchange_rate` numérico.
- NUNCA recalcular historicos — el rate congelado en su fila es la verdad.

### 8.6 Estilo
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

### 9.2 Correr E2E (portal web)
```bash
pnpm e2e:install   # solo primera vez (~150MB chromium)
pnpm e2e           # todos
pnpm e2e tests/e2e/portal-translados.spec.js   # uno solo
```

**Vars de entorno útiles**: `BASE_URL` (default `http://127.0.0.1:8000`), `E2E_USER`
(default `gerente.valencia@demo.test`), `E2E_PASSWORD` (default `password`),
`E2E_TENANT_SLUG` (default `demo-valencia`).

### 9.3 Tests cross-tenant (OBLIGATORIOS al agregar endpoints)
Patrón: crear tenant A y tenant B, usuario en cada uno con permisos completos, intentar acción
cross-tenant, esperar 403 (o 404 si oculta existencia), verificar que el recurso en A no cambió.

```bash
php vendor/bin/phpunit --filter "cross_tenant|other_tenant|detail_audit|standard_api_index_does_not_leak"
```

### 9.4 Pre-push gate
`bin/pre-push.php` corre toda la suite antes de cada push. NO hacer push si falla (emergencia:
`git push --no-verify`, solo con justificación).

### 9.5 Disciplina de tests (OBLIGATORIA)

**Regla innegociable**: la suite de pruebas **SIEMPRE se debe ejecutar** después de crear nuevas
cosas o funciones para verificar que **no se rompe la funcionalidad existente**.

**Y al revés**: toda herramienta nueva / funcionalidad nueva / cambio de comportamiento **debe venir
acompañado de sus tests correspondientes**. Sin tests, no se considera terminado.

Reglas concretas:

1. **Después de cualquier cambio de código** (controller, service, model, request, policy, route,
   migration, sync event, comando Artisan, frontend JS), correr **al menos** los tests del módulo
   afectado ANTES de declararlo listo:
   ```bash
   php vendor/bin/phpunit tests/Feature/<ModuloAfectado>/
   ```
   Si hay dudas de impacto, correr la suite completa: `php vendor/bin/phpunit`.

2. **Funcionalidad nueva = tests nuevos obligatorios**. Mínimo:
   - Test Feature del endpoint nuevo (happy path + caso de error/validación + caso de permiso).
   - Si es multi-tenant: test cross-tenant (ver §9.3).
   - Si es sync: test que cubra el evento en `sync_outbox` y su aplicación por el applier.
   - Si es POS / caja / inventario: test que verifique el movimiento de stock (`StockMovement`)
     y el saldo (`StockBalance`) resultante.
   - Si es dinero: test que verifique doble cuenta (`*_base_amount` + `*_local_amount`) y el
     snapshot del rate.

3. **Tests deben vivir junto al código que prueban**:
   - Backend: `tests/Feature/<Modulo>/<Concepto>Test.php`.
   - E2E (UI portal): `tests/e2e/<portal-seccion>.spec.js`.
   - Nombrar siguiendo la convención existente (PascalCase, sufijo `Test` / `Spec`).

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
pnpm install
pnpm run build           # CRÍTICO para que la web tenga CSS/JS
```

### 10.2 Dev con HMR
```bash
pnpm run dev             # Vite dev server con HMR
php artisan serve        # Laravel dev server (en otra terminal)
```

### 10.3 Deploy al VPS (`217.216.80.158`)
```bash
ssh -i webadmin-vps webadmin@217.216.80.158
cd /opt/inventarioarens-cloud
sudo /usr/bin/env git pull
sudo /usr/bin/env pnpm install --frozen-lockfile
sudo /usr/bin/env pnpm run build
sudo /usr/bin/env php artisan optimize:clear
```

**🚫 JAMÁS usar `php artisan view:cache`** — cachea Blade y los cambios no se ven hasta el próximo
`view:clear`. `optimize:clear` ya lo hace.

**`public/build/` no está en git** — se regenera en cada setup/deploy para evitar mismatch de
hashes entre Windows y Linux.

### 10.4 Stack del VPS
- Nginx + PHP-FPM 8.4 + PostgreSQL 16 nativo (NO Docker).
- HTTPS con Let's Encrypt.
- DNS A: `app.miinventariofacil.com → 217.216.80.158`.
- Bootstrap: `scripts/cloud-api-bootstrap-vps.sh`.

---

## 11. Diagnóstico rápido

| Síntoma | Causa probable | Fix |
|---|---|---|
| CSS/JS no carga en web | Falta `pnpm run build` | Build en server |
| Cambios `.blade.php` no se ven | `view:cache` activo | `php artisan optimize:clear` |
| 401 en API tras deploy | Sesión cacheada | `php artisan optimize:clear` |
| Worker abre ventana negra | Scheduled Task apunta al .cmd directo, no al VBS | Re-registrar con `scripts/sync-worker-task.ps1 install -TenantSlug <slug>` |
| Cambios locales no llegan a la nube | Worker no corriendo o token vencido | `php artisan sync:status {tenant}` + reinstalar task |
| Evento `ignored` en sync_inbox | Falta tipo conocido por el applier | `php artisan sync:apply-inbox <tenant> --limit=200` |
| Tests "duplicate table" local | Concurrencia en DB testing | Correr archivo por archivo o `--process-isolation` |
| Producto no aparece en POS local | Nodo no recibió foto inicial | Forzar pull: `php artisan sync:run {tenant} --pull-only` |
| Login WPF falla con 401 multi-tenant | Token no coincide con X-Tenant | Re-loguear seleccionando empresa correcta |

---

## 12. Documentos de referencia (docs/)

**Arquitectura y diseño** (leer antes de cambios estructurales):
- `docs/ARCHITECTURE.md` — fuente de verdad arquitectural.
- `docs/MODULES.md` — mapa de módulos.
- `docs/API.md` — referencia de endpoints.
- `docs/IMPLEMENTATION_LOG.md` — bitácora cronológica de cambios.

**Infra y deploy**:
- `docs/BUILD.md` — setup local + deploy + CI.
- `docs/ENTORNO_VPS_POSTGRES_LOCAL_2026-07-05.md`
- `docs/ENTORNO_LOCAL_LARAGON_POSTGRES_2026-07-05.md`
- `docs/DOMINIO_APP_MIINVENTARIOFACIL_VPS_2026-07-07.md`
- `docs/API_NUBE_PERMANENTE_Y_PRUEBA_DOMINIO_2026-07-07.md`

**Dominio**:
- `docs/AISLAMIENTO_MULTIEMPRESA_2026-07-05.md` — multi-tenancy.
- `docs/PERMISOS_MODULOS_WPF_2026-07-05.md`
- `docs/MODULO_CAJA_WPF_Y_DATOS_DEMO_2026-07-05.md`
- `docs/MODULO_METODOS_PAGO.md`
- `docs/MODULO_TASAS_CAMBIO_2026-07-08.md`
- `docs/PLAN_MODULO_TRASLADOS_LOGISTICOS_2026-07-09.md` + fases 1-7.

**Sync**:
- `docs/SINCRONIZACION_LOCAL_NUBE_2026-07-05.md` — diseño general.
- `docs/SYNC_API_TRANSPORTE_2026-07-05.md` — endpoints.
- `docs/SYNC_AUTO_WORKER_Y_API_PERMANENTE_2026-07-06.md`
- `docs/SYNC_WORKER_WINDOWS_TAREA_PROGRAMADA_2026-07-06.md`

**Portal admin web**:
- `docs/PORTAL_ADMIN_FRONTEND_BASE_2026-07-07.md`
- `docs/PORTAL_ADMIN_BACKEND_DASHBOARD_2026-07-07.md`
- `docs/PORTAL_ADMIN_USUARIOS_PERMISOS_2026-07-07.md`
- `docs/PORTAL_ADMIN_REPARACION_PERMISOS_VPS_2026-07-07.md`
- `docs/GUIA_UI_ALTA_DENSIDAD_PORTAL_ADMIN_2026-07-07.md`
- `docs/IMPLEMENTACION_PORTAL_TRASLADOS_FASE_1_LISTADO_2026-07-10.md`
- `docs/IMPLEMENTACION_PORTAL_TRASLADOS_FASE_2_DETALLE_2026-07-10.md`

**POS**:
- `docs/POS_RENDIMIENTO_2026-07-05.md`
- `docs/POS_PAGOS_MIXTOS_TASAS_2026-07-09.md`
- `docs/POS_IMEI_ESCANEO_Y_CHECKOUT_2026-07-08.md`
- `docs/POS_FOCO_PERMANENTE_BUSCADOR_2026-07-08.md`

**Demo data**:
- `docs/DATOS_DEMO_MULTIEMPRESA_2026-07-05.md`
- `docs/DEMO_DATA.md`

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
php artisan view:clear
php artisan config:cache

# Frontend
pnpm install
pnpm run build
pnpm run dev

# WPF desktop
dotnet run --project desktop/InventoryDesktop/InventoryDesktop.csproj
dotnet run --project desktop/InventoryDesktop.XamlSmoke/InventoryDesktop.XamlSmoke.csproj   # smoke test XAML post-cambio de .xaml

# Tests
php vendor/bin/phpunit
php vendor/bin/phpunit --filter "cross_tenant"
php vendor/bin/phpunit --process-isolation
pnpm e2e
vendor/bin/pint                       # code style

# Sync
php artisan sync:prepare-local demo-valencia "Demo Valencia" admin@test.test
php artisan sync:issue-token demo-valencia admin@test.test --name=worker --days=365
php artisan sync:run demo-valencia
php artisan sync:apply-inbox demo-valencia --limit=200

# Tenant Admin (Administrador dentro de una empresa existente)
# Asigna rol Administrador con todos los permisos al user en sus empresas.
php artisan access:promote-admin gerente.valencia@demo.test

# Platform Admin (SaaS Master, nivel global, controla todos los grupos/spinoffs)
# Crea el user si no existe, o lo promueve a Platform Admin si ya existe.
php artisan access:create-platform-admin "Nombre Admin" admin@arens.test                  # pass aleatoria
php artisan access:create-platform-admin "Nombre Admin" admin@arens.test --password=Secret1234
# Promover user existente a Platform Admin: usar el mismo create-platform-admin
# (si el email ya existe y NO es platform admin, lo marca is_platform_admin=true).
php artisan access:create-platform-admin "Mi Admin" usuario@yaexiste.com --password=Secret1234
# Endpoints (requieren EnsurePlatformAdmin):
#   GET  /api/master/admins   - lista Platform Admins
#   POST /api/master/admins   - crea uno nuevo (o promueve si ya existe). Devuelve initial_password si fue creado.
# Endpoint SIN tenant middleware (login global sin empresa):
#   POST /api/auth/platform-login - login exclusivo para Platform Admins.
#     Emite un AuthToken con tenant_id=null (no scoped). Solo sirve para /api/master/*.
#     Si el user no es platform_admin responde 422.

# VPS
ssh -i C:\Users\gafit\.ssh\webadmin-vps webadmin@217.216.80.158
ssh -i C:\Users\gafit\.ssh\webadmin-vps webadmin@217.216.80.158 "sudo -u postgres psql -d inventory_arens -c 'SELECT 1'"

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
- ❌ Commitear `public/build/`.
- ❌ Saltar el pre-push hook sin justificación documentada.
- ❌ Confundir INVENTARIOARENS con MiInventarioFácil (ver §2).
- ❌ Tocar `.harness/`, `.codex/`, `.githooks/`, `.github/workflows/` sin OK explícito.
- ❌ Agregar Alpine.js o Livewire sin conversación previa.
- ❌ Recalcular rates históricos — los snapshots son la verdad.
- ❌ Sobreescribir ventas/pagos/caja (deben ser append-only + auditados).
- ❌ Borrar `.env` ni `.env.example`.
- ❌ Entregar código nuevo o modificados sin correr tests (ver §9.5).
- ❌ Crear feature/herramienta sin sus tests asociados.
- ❌ Agregar nuevos NuGets al proyecto WPF `InventoryDesktop/` sin conversación previa. **Único
  autorizado**: `MaterialDesignThemes 5.2.1` (introducido el 2026-07-11 para rediseño de login —
  paleta `#4D35FF` se mantiene vía override de `MaterialDesign.Brush.Primary.*`). Cualquier otro
  paquete debe consultarse primero.

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

## 16. Auditoría de backend 2026-07-11

El backend fue auditado el 2026-07-11 (10 agentes en paralelo, scope `app/`, `database/`, `routes/`, `tests/`).
Documentación detallada en `docs/AUDIT_2026-07-11/`:

- **Score general:** 6.8/10.
- **Resumen ejecutivo:** `docs/AUDIT_2026-07-11/00_RESUMEN_EJECUTIVO.md`.
- **Auditorías detalle:** `01_MULTI_TENANCY.md` (8.5), `02_AUTH_SEGURIDAD.md` (6.5), `03_SYNC_ENGINE.md` (6), `04_INVENTARIO_IMEI.md` (6), `05_POS_CAJA_TASAS.md` (7), `06_TRASLADOS.md` (7), `07_CXC_CXP_GARANTIAS.md` (6.5), `08_API_DESIGN.md` (7), `09_PERFORMANCE.md` (5.5), `10_CALIDAD_TESTS.md` (6-7).
- **Roadmap tachable:** `docs/AUDIT_2026-07-11/ROADMAP.md` (P0/P1/P2/P3/P4 con status).
- **Contrato para frontend:** `docs/AUDIT_2026-07-11/CONTRATO_PARA_FRONTEND.md` (lo que la IA frontend necesita saber).

**Regla de oro:** después de cada fix, actualizar el item correspondiente en `ROADMAP.md` cambiando
`- [ ]` → `- [x] — FECHA — descripción corta`. Si el fix descubre un nuevo issue, agregarlo al final
del documento de auditoría correspondiente.

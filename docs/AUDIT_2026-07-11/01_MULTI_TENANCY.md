# Auditoría Multi-Tenancy — 2026-07-11

**Score: 8.5 / 10**
**Estado:** Maduro. Solo falta endurecer un par de puntos.

---

## 1. Lo confirmado

### Core plumbing (`app/Support/Tenancy/*`)

- **`TenantManager`** — minimal, correcto. Tres responsabilidades: `set()`, `current()`, `require()` (throws `TenantNotResolvedException` cuando unset).
- **`TenantScope`** — aplica `where tenant_id = current` y **silentemente no-op cuando no hay tenant** (correcto para CLI; cuestionable para HTTP).
- **`BelongsToTenant`** trait — `bootBelongsToTenant()` agrega el global scope y auto-rellena `tenant_id` en `creating` desde `TenantManager::require()`.
- **`TenantNotResolvedException`** — typed exception. Solo thrown por `TenantManager::require()`.
- **`TenancyServiceProvider`** — registra `TenantManager` como `scoped()`. Buena elección: state es per-request.

### Middleware (`app/Modules/Tenancy/Middleware/ResolveTenant.php`)

`ResolveTenant::handle` (líneas 18-49) hace lo correcto en orden:
1. Resuelve tenant desde `X-Tenant` header → route param → `?tenant=` → domain.
2. **404 si no hay tenant** (línea 22).
3. **403 si token's tenant_id != request tenant** (líneas 24-28). Cubre stolen-token attacks.
4. **403 si user no es `active` member of tenant** (líneas 30-36). Cubre multi-tenant impersonation.
5. **Setea `TenantManager`** + `setPermissionsTeamId()` (líneas 38-42).
6. **`finally { $this->tenants->clear() }`** (línea 47) — garantiza limpieza aún si downstream throws.

### Rutas

- Cada API route autenticado está wrappeado en `['api.auth', 'tenant']` middleware. Sin gaps.
- Auth exemptions (`app/Modules/Auth/routes.php`) intencionales y seguras:
  - `POST /api/auth/tenants` — público, solo devuelve tenants activos del user.
  - `POST /api/auth/login` — bajo `tenant` solamente.
  - `POST /api/auth/switch-tenant` — bajo `api.auth` solamente.

### Model coverage — 45 de 50 modelos correctamente scoped

Todos los modelos de negocio usan `BelongsToTenant`. Confirmado en `app/Modules/*/Models/`:
Audit, Branches, CashRegister (3), Customers, Currency (2), FinancialAdjustments, Inventory (3), InventoryTransfers (5), PaymentMethods, PaymentReceipts, POS (2), ProductEntries (2), ProductExits (2), Products (4), PurchaseReturns (2), Purchases (2), Sales (2), SalesReturns (2), Suppliers, Warehouses, Warranties (2), AccountsPayable (2), AccountsReceivable (2).

`Tenant`, `AuthToken`, `InventoryTransferRequest`, `InventoryTransferRequestItem` son intencionalmente NO scoped (cross-tenant por diseño).

### Database schema — ejemplar

- Cada tabla tenant-scoped usa composite unique keys: `['tenant_id', 'sku']`, etc.
- Cada FK cross-table entre tablas tenant-scoped es **composite**: `$table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id','id'])->on('warehouses')`. Esto hace que DB-level integrity rechace cross-tenant FK violations.

Ejemplos clave:
- `database/migrations/2026_07_02_182000_create_stock_movements_table.php:28-34`
- `database/migrations/2026_07_02_183000_create_stock_balances_table.php:22-28`
- `database/migrations/2026_07_02_192000_create_product_units_table.php:26-39`
- `database/migrations/2026_07_09_210000_create_inventory_transfer_logistics_tables.php:70-130`

Esta defensa a nivel DB es la **garantía más fuerte posible**.

### Validation rules

Cada `Rule::exists(...)` y `Rule::unique(...)` está scoped con `->where('tenant_id', $tenantId)`. Confirmado en 40+ `*Request.php` files.

### Policies (defense layer 3)

Cada policy que otorga acceso a row específica tiene `ownsResource()` chequeando `(int) $model->tenant_id === (int) $tenant->id`.

### Console commands

- `ApplySyncInboxCommand:28` setea `TenantManager` antes de delegar.
- `SyncWorkerService::run:35,101` setea y limpia en `try/finally`.
- `PromoteTenantAdministratorCommand:57,67` itera tenants con set/clear.
- `PrepareLocalTenantCommand:99` setea tenant para spatie permission setup.

### Sin `withoutGlobalScope` en ningún lugar

`grep withoutGlobalScope` → **0 matches**. Excelente.

### Test coverage

- `tests/Feature/Tenancy/TenantIsolationTest.php` — 3 tests.
- `tests/Feature/Tenancy/OperationalTenantIsolationTest.php` — 282 líneas full POS + inventory.
- `tests/Feature/Inventory/InventorySchemaIsolationTest.php` — 4 tests DB-level FK.
- `tests/Feature/Inventory/SerializedProductUnitTest.php:75-117` — cross-tenant FK rejection.
- 20+ `cross_tenant` / `other_tenant` test method names.

---

## 2. Issues

### [MEDIUM-HIGH] `TenantTransferSetting` no tiene `BelongsToTenant`
- **File:** `app/Modules/InventoryTransfers/Models/TenantTransferSetting.php`
- `'tenant_id'` está en `#[Fillable(...)]` pero el modelo NO usa el trait.
- **Riesgo:** Cualquier `TenantTransferSetting::query()->get()` devuelve filas de TODOS los tenants.
- **Mitigación actual:** `grep TenantTransferSetting app/` solo devuelve el model file (sin uso). Riesgo dormido.
- **Fix:** Effort S. Agregar `use BelongsToTenant;` + test de aislamiento.

### [MEDIUM] `DB::table(...)` usage depende 100% de filtros manuales
- ~80 `DB::table` calls en `AdminPortal/Services/*` y `Sync/Services/*`.
- **Todos** incluyen `->where('tenant_id', $tenantId)` (verificado).
- **Riesgo:** No hay static check, no hay runtime guard, no hay test que asegure que futuros `DB::table` agregados incluyan el filtro.
- **Fix:** PHPStan custom rule que escanee `DB::table` calls y asserte presence del tenant filter.

### [LOW-MEDIUM] `TenantScope::apply` no-op silencioso cuando no hay tenant
- **File:** `app/Support/Tenancy/Scopes/TenantScope.php:16-18`
- Comportamiento: En HTTP context sin `tenant` middleware, un `Model::query()->get()` devuelve filas de **todos** los tenants.
- **Mitigación:** Las defense layers 2 (validation rules) y 3 (policies) catch esto, pero layer 1 no.
- **Fix:** Agregar `TenantScopeStrict` variant opt-in para código nuevo.

### [LOW] `Sync\Services\SyncInitialSnapshotService` no setea TenantManager consistentemente
- Solo 1 `set()` call en 400+ líneas. El resto usa `DB::table('...')->where('tenant_id', $tenant->id)` directamente.
- Funciona, pero si la snapshot cambia a Eloquent mid-file, el global scope aplicaría repentinamente.

### [LOW] Policies usan `current()` en lugar de `require()`
- 22 policy files usan `app(TenantManager::class)->current()` (returns null if unset).
- Funciona porque el short-circuit `$tenant && (...)` lo niega silencioso.
- **Fix:** Usar `require()` para fallar loud en lugar de deny silencioso.

### [LOW] `AuthService::issueSessionForTenant` setea TenantManager DESPUÉS de crear AuthToken
- **File:** `app/Modules/Auth/Services/AuthService.php:60-72`
- Token se crea (línea 61) ANTES de setear tenant (línea 72).
- Funciona porque `AuthToken` NO es `BelongsToTenant`-scoped.
- **Riesgo:** Un refactor futuro agregando trait a `AuthToken` rompería login.
- **Fix:** Reordenar líneas.

### [LOW] Resource classes exponen `tenant_id` en JSON
- Múltiples `*Resource.php` files exponen `tenant_id`.
- Trivial information disclosure; cliente ya conoce su tenant via `auth/me`.

### [LOW] `User::tenants()` no filtra `status='active'` por default
- **File:** `app/Models/User.php:25-30`
- Cada call site lo hace explícitamente. Patrón consistente pero propenso a olvido.

---

## 3. Propuestas

| # | Propuesta | Esfuerzo | Impacto |
|---|---|---|---|
| 4.1 | Agregar `BelongsToTenant` a `TenantTransferSetting` + test | S | Cierra riesgo dormant |
| 4.2 | PHPStan rule para `DB::table` sin `where('tenant_id', ...)` | M | Hardening alto |
| 4.3 | `TenantScopeStrict` opt-in para nuevos controllers | S | Defensa en profundidad |
| 4.4 | Cambiar `current()` por `require()` en 22 policies | S | Diagnóstico más claro |
| 4.5 | Reordenar `AuthService::issueSessionForTenant` | S | Previene bug futuro |
| 4.6 | Builder macro `requireTenantScope()` opt-in | M | Para controllers nuevos |
| 4.7 | Drop `tenant_id` de `*Resource` JSON output | S | Information hygiene |
| 4.8 | `User::tenants()` filtrar `status='active'` por default | S | Previene olvidos |
| 4.10 | Tests cross-tenant en módulos faltantes (Sales, PurchaseReturns, CxC/CxP, Currency, Warranties, CashRegister deep paths) | M | Cobertura |
| 4.11 | Tests de leak integration para `AdminPortal/Services/*` | L | Catch cualquier filtro olvidado |

---

## 4. Missing traits (revisión final)

| Modelo | Archivo | Tiene `tenant_id` | Tiene `BelongsToTenant` | Severidad |
|---|---|---|---|---|
| **TenantTransferSetting** | `app/Modules/InventoryTransfers/Models/TenantTransferSetting.php` | Sí | **NO** | **MEDIUM-HIGH** |

Todos los demás modelos con `tenant_id`:
- Tienen el trait (45 modelos), O
- Son cross-tenant por diseño y documentados en AGENTS.md §4: `Tenant`, `AuthToken`, `InventoryTransferRequest`, `InventoryTransferRequestItem`.

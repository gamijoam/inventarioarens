# Diseno: Resource-level Scope (Fase 3)

> **Fecha:** 2026-07-13
> **Para:** Backend devs + Frontend devs.
> **Estado:** Implementandose ahora. Tests + deploy vienen despues.
> **Documentacion relacionada:** `docs/PERMISSIONS_HIERARCHY_DESIGN_2026-07-13.md` (Fase 1+2) y `docs/INSTRUCCIONES_FRONTEND_PERMISSIONS.md`.

---

## 1. Vision general

Las Fases 1 y 2 agregaron **arbol jerarquico + field masking + overrides individuales**. Falta lo mas grave: **multi-sucursal seguro**.

Hoy: cualquier user con `sales.view` ve las ventas de **TODAS** las sucursales del tenant, no solo las suyas. Eso es un leak operacional.

Fase 3 agrega **scopes por recurso** que filtran queries a nivel de aplicacion:
- `branch` — solo ve datos de su(s) sucursal(es) asignada(s).
- `warehouse` — solo ve stock de su(s) deposito(s) asignado(s).
- `customer_group` — solo ve clientes de su(s) grupo(s) asignado(s).
- `vendor_of` — el user actua como "vendor" de ciertos grupos, solo ve CxC y ventas de esos grupos.

---

## 2. Decisiones de diseno

### 2.1 Default behavior: **default-allow** con warning

Razon: el sistema actual ya funciona con miles de ventas. Si activo scope con default-deny, **todos los users existentes pierden acceso** hasta que se les asignen scopes. Eso es disruptivo y bloquearia la operacion.

**Solucion: default-allow** que muestra un banner en la UI:
- Si el user tiene asignado 0 branches: ve todas las branches, pero la UI muestra "ALERTA: este user no tiene sucursales asignadas, recomendado restringir".
- Si tiene 1+ branches: ve solo esas branches, sin warning.

> Esto es **backward compatible**: no rompe nada, da un camino gradual de adopcion. El admin puede ir restringiendo usuarios uno a uno.

### 2.2 Nombre del modelo `CustomerGroup`

No existe como modelo en `app/Modules/Customers/`. Hay `Customer` (singular) pero no `CustomerGroup`. **Voy a crearlo** como un agrupador opcional de clientes:

```sql
CREATE TABLE customer_groups (
    id, tenant_id, code, name, description, status, ...
);
```

Y `customers.customer_group_id` (nullable FK) para asignar clientes a grupos.

### 2.3 Vendor-of como scope

En el contexto de Valery, "vendor" significa "vendedor" (la persona que atiende clientes). El scope `vendor_of` significa: este user SOLO ve datos (CxC, ventas, clientes) de los clientes que pertenecen a los `customer_group_ids` que se le asignen.

### 2.4 Wildcards para scope vacio

Si un user tiene `branch_ids: []` (lista vacía), el comportamiento depende del default:
- **default-allow**: ve todas.
- **default-deny**: no ve ninguna (futuro).

Para esta implementación, el default es **allow**. Asi que `branch_ids: []` significa "sin restricciones = ve todas".

### 2.5 Audit log de scopes

Cada cambio de scope se registra en `audit_logs` con actions:
- `access.user.scope_assigned` (PUT)
- `access.user.scope_removed` (DELETE individual, futuro)

---

## 3. Modelo de datos

### 3.1 Nueva tabla `customer_groups`

```sql
CREATE TABLE customer_groups (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    code VARCHAR(50) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP, updated_at TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, code)
);
```

Modelo: `app/Modules/Customers/Models/CustomerGroup.php` con `BelongsToTenant` + relacion `hasMany Customers`.

### 3.2 Modificar `customers` para tener `customer_group_id`

```sql
ALTER TABLE customers ADD COLUMN customer_group_id BIGINT NULL REFERENCES customer_groups(id) ON DELETE SET NULL;
```

### 3.3 Tabla pivote `user_branch_scopes`

```sql
CREATE TABLE user_branch_scopes (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    branch_id BIGINT NOT NULL,
    created_at TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, user_id, branch_id)
);
```

### 3.4 Tabla pivote `user_warehouse_scopes`

```sql
CREATE TABLE user_warehouse_scopes (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    warehouse_id BIGINT NOT NULL,
    created_at TIMESTAMP,
    FKs, UNIQUE(tenant_id, user_id, warehouse_id)
);
```

### 3.5 Tabla pivote `user_customer_group_scopes`

```sql
CREATE TABLE user_customer_group_scopes (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    customer_group_id BIGINT NOT NULL,
    created_at TIMESTAMP,
    FKs, UNIQUE(tenant_id, user_id, customer_group_id)
);
```

### 3.6 Tabla pivote `user_vendor_assignments`

Esta tabla es **distinta** de las scopes: indica que el user ES VENDOR de esos grupos. Se usa en CxC y ventas para filtrar "lo que YO vendi / atiendo".

```sql
CREATE TABLE user_vendor_assignments (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    customer_group_id BIGINT NOT NULL,
    created_at TIMESTAMP,
    FKs, UNIQUE(tenant_id, user_id, customer_group_id)
);
```

### 3.7 Defaults

Para **backward compatibility**, ningún user tiene scopes asignados al inicio. Eso significa que `users` ven todo por default (default-allow).

---

## 4. ScopeResolver service

```php
class ScopeResolver {
    public function branchIdsFor(User $user): ?array;     // null = sin scope (ve todo)
    public function warehouseIdsFor(User $user): ?array;
    public function customerGroupIdsFor(User $user): ?array;
    public function vendorOfGroupIdsFor(User $user): ?array;
    public function applyBranchScope(Builder $query, User $user, string $column = 'branch_id'): Builder;
    public function applyWarehouseScope(Builder $query, User $user, string $column = 'warehouse_id'): Builder;
    public function applyCustomerGroupScope(Builder $query, User $user, string $column = 'customer_group_id'): Builder;
    public function applyVendorScope(Builder $query, User $user, string $column = 'user_id'): Builder;  // filtra por user_id en sales
}
```

### 4.1 Comportamiento default-allow

```php
public function applyBranchScope(Builder $query, User $user, string $column = 'branch_id'): Builder
{
    $branchIds = $this->branchIdsFor($user);

    if ($branchIds === null) {
        return $query;  // sin scope = ve todo
    }

    return $query->whereIn($column, $branchIds);
}
```

Si `branchIds` es `null` (no tiene scope asignado) → no filtra.
Si `branchIds` es `[]` (lista vacía, asignado pero sin items) → no filtra (default-allow).
Si `branchIds` es `[1, 2, 3]` → filtra.

### 4.2 Where se aplica

Controllers que filtran:
- `Sales\SalesOrderController::index/show` → `applyBranchScope($query, $user, 'branch_id')`.
- `Sales\SalesController::index` → idem.
- `Inventory\StockMovementController::index` → `applyWarehouseScope`.
- `Inventory\InventoryTransferController::index` → ambas.
- `CashRegister\CashRegisterSessionController::index` → branch.
- `Kardex\KardexController::product` → warehouse.
- `AccountsReceivable\AccountsReceivableController::index` → customer_group + vendor_of.
- `AccountsReceivable\PaymentReceiptController::index` → customer_group.

---

## 5. API endpoints nuevos

### 5.1 `GET /api/tenants/{tenant}/users/{user}/scopes`

Lista todos los scopes del user en el tenant.

**Response 200:**
```json
{
  "data": {
    "user_id": 5,
    "tenant_id": 1,
    "branches": [1, 3],
    "warehouses": [2, 4, 5],
    "customer_groups": [1],
    "vendor_of": [1, 2],
    "counts": {
      "branches": 2,
      "warehouses": 3,
      "customer_groups": 1,
      "vendor_of": 2
    },
    "expanded": {
      "branches": [
        {"id": 1, "code": "VAL", "name": "Valencia"},
        {"id": 3, "code": "MAR", "name": "Maracaibo"}
      ],
      "warehouses": [...],
      "customer_groups": [...],
      "vendor_of": [...]
    }
  }
}
```

**Permite:** `users.view`.

### 5.2 `PUT /api/tenants/{tenant}/users/{user}/scopes/branches`

Reemplaza la lista de branches del user.

**Request:**
```json
{ "branch_ids": [1, 3, 5] }
```

`PUT` con body `{"branch_ids": []}` = "sin restricción, ve todo".
`PUT` con body `{"branch_ids": [1]}` = "solo ve branch 1".

**Response 204.**

**Permite:** `users.update`.

### 5.3 `PUT /api/tenants/{tenant}/users/{user}/scopes/warehouses`

Mismo formato que branches pero con `warehouse_ids`.

### 5.4 `PUT /api/tenants/{tenant}/users/{user}/scopes/customer-groups`

Mismo formato pero con `customer_group_ids`.

### 5.5 `PUT /api/tenants/{tenant}/users/{user}/scopes/vendor-of`

Mismo formato pero con `customer_group_ids`. El nombre del campo es el mismo pero semánticamente es "este user es vendor de estos grupos".

---

## 6. Tests

**ScopeApiTest.php** (nuevo, ~10 tests):
- `test_show_returns_all_scope_lists_empty_when_unset`
- `test_show_returns_expanded_scope_objects`
- `test_replace_branches_is_idempotent`
- `test_replace_warehouses_is_idempotent`
- `test_replace_customer_groups_is_idempotent`
- `test_replace_vendor_of_is_idempotent`
- `test_scope_filtering_limits_sales_queries` (integration con SalesController)
- `test_scope_filtering_limits_stock_movements_queries`
- `test_user_without_scope_sees_everything_default_allow`
- `test_cross_tenant_scope_replacement_returns_404`
- `test_scope_assigned_audit_log_recorded`

**ScopeResolverTest.php** (unit, ~5 tests):
- `test_branch_ids_for_returns_null_when_unset`
- `test_apply_branch_scope_with_null_keeps_query_intact`
- `test_apply_branch_scope_with_ids_adds_where_in`
- `test_apply_warehouse_scope`
- `test_apply_vendor_scope_filters_by_user_id`

**Suite**: 618 → ~635 tests.

---

## 7. Cambios en Resources / Controllers

### 7.1 Customers: agregar `customer_group_id`

- `app/Modules/Customers/Models/Customer.php`: agregar relacion `customerGroup()`.
- `app/Modules/Customers/Resources/CustomerResource.php`: agregar `customer_group_id` y `customer_group: CustomerGroupResource`.
- `app/Modules/Customers/Controllers/CustomerController.php`: filtrar por `customer_group_id` cuando el user tiene scope.
- `app/Modules/Customers/Requests/StoreCustomerRequest.php`: agregar validacion para `customer_group_id`.

### 7.2 Sales: filtrar por branch + vendor_of

- `Sales\SalesController::index`: agregar `applyBranchScope($query, user)` + `applyVendorScope($query, user)`.
- `Sales\SalesOrderController::index`: idem.

### 7.3 Inventory: filtrar por warehouse + branch

- `Inventory\StockMovementController::index`: `applyWarehouseScope`.
- `Inventory\InventoryTransferController::index`: ambas.

### 7.4 Kardex: filtrar por warehouse

- `Kardex\KardexService::product`: `applyWarehouseScope`.

### 7.5 AccountsReceivable: filtrar por customer_group + vendor_of

- `AccountsReceivable\AccountsReceivableController::index`: ambas.

---

## 8. Riesgos y decisiones a confirmar

1. **Default behavior**: voy con **default-allow** (no rompe nada). Si quieres default-deny, hay que migrar usuarios existentes que tengan `users.view` etc.
2. **Performance**: cada query con scope agrega un `whereIn`. El cost es O(n) donde n = scopes. Para tenant con 50 branches y user con scope de 10 branches, no es problema.
3. **Tests existentes**: `SalesController::index` test asume que ve TODAS las ventas. Tengo que actualizarlo o agregar scope=allow a esos tests.
4. **Sync local↔nube**: ¿se sincronizan los scopes? Recomiendo **NO** (Fase 4+). Por ahora cada nodo configura scopes localmente via artisan o API.
5. **CustomerGroup model**: se crea como minimo. Si el cliente quiere más features (jerarquía, comision por grupo), queda para Fase 4.

---

## 9. Roadmap de implementacion (esta sesion)

| Paso | Detalle | Esfuerzo |
|---|---|---|
| 1 | Migracion `customer_groups` + ALTER `customers` | 30 min |
| 2 | Modelo `CustomerGroup` + relacion con `Customer` | 20 min |
| 3 | 4 migraciones de pivote (branch, warehouse, customer_group, vendor) | 30 min |
| 4 | 4 modelos pivote con `BelongsToTenant` | 30 min |
| 5 | `ScopeResolver` service | 1 h |
| 6 | 5 endpoints (GET + 4 PUT) en `UserScopeController` | 1.5 h |
| 7 | Integrar ScopeResolver en `CapabilityResolver` | 30 min |
| 8 | Aplicar scope en 6 controllers (Sales, Stock, InvTransfer, CashReg, Kardex, CxC) | 1.5 h |
| 9 | Tests E2E (15 nuevos) | 2 h |
| 10 | Doc `docs/INSTRUCCIONES_FRONTEND_SCOPES.md` | 1 h |
| 11 | Commit + push + deploy | 30 min |

**Total estimado:** ~10 h (~1.5 dias de dev senior).

---

## 10. Lo que NO se hace en esta sesion (queda pendiente)

- **Sync nube→local de scopes** (Fase 4+).
- **Default-deny configurable** (queda como flag futuro en `settings.manage`).
- **Permisos condicionales** (Fase 4+): `sales.cancel.cond:no_payments` etc.
- **Wildcards de scope** (`sales.scope:own_*`).
- **Frontend UI completa** del tab "Scopes" en portal admin.
- **CustomerGroup con jerarquía** (padre-hijo).

---

## 11. Cambios backend (resumen para code review)

| Archivo | Tipo | Descripcion |
|---|---|---|
| `database/migrations/2026_07_13_010000_create_customer_groups_table.php` | NEW | Tabla customer_groups |
| `database/migrations/2026_07_13_010100_add_customer_group_to_customers.php` | NEW | ALTER customers |
| `database/migrations/2026_07_13_010200_create_user_scope_tables.php` | NEW | 4 tablas pivote de scope |
| `app/Modules/Customers/Models/CustomerGroup.php` | NEW | Modelo con BelongsToTenant + hasMany Customers |
| `app/Modules/Customers/Models/Customer.php` | mod | Agregar relacion `customerGroup()` |
| `app/Modules/AccessControl/Models/UserBranchScope.php` | NEW | Modelo pivote |
| `app/Modules/AccessControl/Models/UserWarehouseScope.php` | NEW | Modelo pivote |
| `app/Modules/AccessControl/Models/UserCustomerGroupScope.php` | NEW | Modelo pivote |
| `app/Modules/AccessControl/Models/UserVendorAssignment.php` | NEW | Modelo pivote |
| `app/Modules/AccessControl/Services/ScopeResolver.php` | NEW | Aplica scope en queries |
| `app/Modules/AccessControl/Controllers/UserScopeController.php` | NEW | 5 endpoints (GET + 4 PUT) |
| `app/Modules/AccessControl/Services/CapabilityResolver.php` | mod | Llama ScopeResolver para integrar con effective-permissions |
| `app/Modules/AccessControl/routes.php` | mod | 5 rutas nuevas |
| `app/Modules/Sales/Controllers/SalesOrderController.php` | mod | Aplica applyBranchScope + applyVendorScope |
| `app/Modules/Sales/Controllers/SalesController.php` | mod | Aplica applyBranchScope |
| `app/Modules/Inventory/Controllers/StockMovementController.php` | mod | Aplica applyWarehouseScope |
| `app/Modules/Inventory/Controllers/InventoryTransferController.php` | mod | Aplica ambas |
| `app/Modules/CashRegister/Controllers/CashRegisterSessionController.php` | mod | Aplica applyBranchScope |
| `app/Modules/Kardex/Controllers/KardexController.php` | mod | Pasa user_id para scope de vendor |
| `app/Modules/AccountsReceivable/Controllers/AccountsReceivableController.php` | mod | Aplica applyCustomerGroupScope + applyVendorScope |
| `tests/Feature/AccessControl/ScopeApiTest.php` | NEW | ~10 tests E2E |
| `tests/Unit/AccessControl/ScopeResolverTest.php` | NEW | ~5 tests unit |
| `docs/INSTRUCCIONES_FRONTEND_SCOPES.md` | NEW | Contrato API para el frontend |

**Total:** ~12 archivos nuevos, 8 modificados, ~2 docs. ~1.5 dias de dev senior.
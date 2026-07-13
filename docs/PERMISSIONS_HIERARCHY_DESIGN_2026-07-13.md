# Diseno: Arbol de Permisos + Field Masking + Overrides (Fase 1 + 2)

> **Fecha:** 2026-07-13
> **Para:** Backend devs + Frontend devs (portal admin)
> **Estado:** Implementandose ahora (Fase 1 + 2). Fase 3 queda pendiente.
> **Suite actual:** 596 tests, 594 passed, 2 skipped, 0 failures.

---

## 1. Vision general

El sistema de permisos actual es **binario** (puede/no puede, con scope por tenant correcto) pero **no escala** a casos reales:

- Cualquier user que ve `sales_items` ve `unit_cost` (leak de margen).
- Cualquier user que ve `sales` ve las ventas de TODAS las sucursales del tenant.
- No hay "preview" de que puede hacer un user antes de que se loguee.

**Fase 1+2** agrega **3 mejoras de seguridad concretas** sin romper backward compat:

1. **Arbol jerarquico navegable** para que el admin entienda que hay disponible (Fase 1).
2. **Field-level masking** para ocultar campos sensibles (costos) a roles de baja confianza (Fase 2).
3. **Overrides individuales** por user para excepciones (ej: "Lucia ademas tiene inventory.adjust aunque su rol no") (Fase 2).

**Fase 3** (resource-level scope por branch/warehouse/customer-group/vendor) queda **pendiente** y se documenta aparte.

---

## 2. Fase 1 — Arbol jerarquico + Editor de Perfiles

### 2.1 Endpoints nuevos en `/api/access/*`

| Endpoint | Verbo | Permiso requerido | Que hace |
|---|---|---|---|
| `/api/access/permission-catalog` | GET | `users.view` | Devuelve el catalogo completo de permisos en formato jerarquico (arbol navegable). |
| `/api/access/roles/{role}/duplicate` | POST | `roles.create` | Clona un rol existente (base o custom) en uno nuevo del mismo tenant. |
| `/api/access/roles/{role}/preview` | GET | `roles.view` | Devuelve metadata del rol: cuantos permisos, en cuantos modulos, wildcards peligrosos, etc. |

### 2.2 Shape del catalog

```json
{
  "data": {
    "modules": [
      {
        "module": "sales",
        "label": "Ventas",
        "verb_count": 3,
        "actions": [
          { "verb": "view", "label": "Ver ventas", "permission": "sales.view" },
          { "verb": "create", "label": "Crear ventas", "permission": "sales.create" },
          { "verb": "cancel", "label": "Cancelar ventas", "permission": "sales.cancel", "danger": "high" }
        ]
      },
      ...
    ],
    "verbs": [
      { "name": "view", "label": "Ver" },
      { "name": "create", "label": "Crear" },
      ...
    ],
    "total_permissions": 100
  }
}
```

### 2.3 Shape del duplicate

```json
// Request
POST /api/access/roles/{role}/duplicate
{ "name": "Cajero Senior" }

// Response 201
{
  "data": {
    "id": 18,
    "name": "Cajero Senior",
    "permissions": ["sales.view", "sales.create", ...]
  }
}
```

### 2.4 Shape del preview

```json
{
  "data": {
    "role_id": 18,
    "name": "Cajero Senior",
    "permission_count": 22,
    "module_count": 5,
    "modules": ["sales", "pos", "cash_register", "customers", "inventory"],
    "wildcards_count": 0,
    "protected": false
  }
}
```

---

## 3. Fase 2 — Field Masking + Overrides

### 3.1 Nuevo permiso: `finance.costs.view`

Agregado a `BasePermissions::PERMISSIONS` y asignado a los 3 roles que tienen sentido:
- **Owner**: SI
- **Administrador**: SI
- **Gerente**: SI
- Vendedor / Almacen / Auditor: NO

Resources que exponen `unit_cost` / `base_unit_cost` / `total_cost` aplican `->when($user->can('finance.costs.view'), ...)`. Si el user NO tiene el permiso, el campo viene `null`.

### 3.2 Migracion: `user_permission_overrides`

```sql
CREATE TABLE user_permission_overrides (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    permission VARCHAR(150) NOT NULL,
    effect VARCHAR(10) NOT NULL CHECK (effect IN ('allow','deny')),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(tenant_id, user_id, permission)
);
CREATE INDEX user_perm_overrides_user_idx ON user_permission_overrides(tenant_id, user_id);
```

### 3.3 Endpoints nuevos

| Endpoint | Verbo | Permiso | Body | Proposito |
|---|---|---|---|---|
| `/api/access/users/{user}/overrides` | GET | `users.view` | — | Lista overrides del user. |
| `/api/access/users/{user}/overrides` | PUT | `users.update` | `{items:[{permission, effect}]}` | Reemplaza todos los overrides del user (idempotente). |
| `/api/access/users/{user}/overrides/{permission}` | DELETE | `users.update` | — | Quita un override puntual. |
| `/api/access/users/{user}/effective-permissions` | GET | `users.view` | — | Devuelve permisos efectivos (roles union extras menos denies) para preview. |

### 3.4 Resolucion de permisos efectivos (matematica)

```
effective_perms(user) =
    perms(roles(user))   # union de todos los permisos de todos los roles del user
    UNION
    overrides(user).where(effect='allow')   # extras
    MINUS
    overrides(user).where(effect='deny')    # quitados

where perms(roles) = sum de BasePermissions::ROLE_PERMISSIONS + custom role permissions
```

**Reglas:**
- `deny` gana sobre `allow` para el mismo permiso (idempotente con `MINUS`).
- Wildcards NO se expanden en este nivel (será Fase 4).
- `finance.costs.view` aplica ANTES de la serializacion del Resource (masking a nivel de campo).

### 3.5 Shape de overrides

```json
// GET /api/access/users/{user}/overrides
{
  "data": {
    "items": [
      { "permission": "inventory.adjust", "effect": "allow", "created_at": "..." },
      { "permission": "sales.cancel", "effect": "deny", "created_at": "..." }
    ],
    "extra_count": 1,
    "deny_count": 1
  }
}

// PUT /api/access/users/{user}/overrides
{
  "items": [
    { "permission": "inventory.adjust", "effect": "allow" },
    { "permission": "sales.cancel", "effect": "deny" }
  ]
}
// Response: 204 No Content (idempotente)
```

### 3.6 Shape de effective-permissions (preview)

```json
// GET /api/access/users/{user}/effective-permissions
{
  "data": {
    "user_id": 5,
    "permissions": [
      "sales.view", "sales.create", "inventory.adjust", "pos.view", ...
    ],
    "permission_count": 47,
    "extras": ["inventory.adjust"],
    "denied": ["sales.cancel"]
  }
}
```

---

## 4. Cambios en Resources (Field Masking)

Los 4 Resources principales que exponen costos:

| Resource | Campos afectados |
|---|---|
| `PurchaseItemResource` | `unit_cost`, `base_unit_cost`, `total_cost` |
| `ProductEntryItemResource` | `unit_cost` |
| `StockMovementResource` | `unit_cost` |
| `KardexService` (POS) | `unit_cost` |

Cambio minimo: envolver con `$this->when($request->user()->can('finance.costs.view'), ...)`.

---

## 5. Tests nuevos (resumen)

**Fase 1:**
- `PermissionCatalogTest`: catalog devuelve jerarquía correcta, 101 permisos, 33 modulos.
- `RoleDuplicateTest`: clonar rol base, clonar custom, validar que permisos se preservan.
- `RolePreviewTest`: preview devuelve counts correctos.

**Fase 2:**
- `UserPermissionOverrideTest`: CRUD idempotente, deny gana sobre allow, audit log registrado, deny self-overrides bloqueado.
- `FinanceCostsVisibilityTest`: Vendedor NO ve unit_cost, Gerente SI lo ve, Auditor NO lo ve.
- `EffectivePermissionsTest`: union de roles + extras - denies es correcto.

**Total estimado:** 12-15 tests nuevos. Suite pasaria de 596 a ~610-612.

---

## 6. Lo que NO se hace en este PR (queda pendiente)

- **Fase 3** — Resource-level scope (sucursales, almacenes, customer-groups, vendor-of). Ver `docs/PERMISSIONS_PENDING_2026-07-13.md`.
- **Wildcards** (`sales.*`, `inventory.transfers.*`) — se difiere hasta Fase 4.
- **Permisos condicionales** (state-based, threshold-based) — Fase 4 o 5.
- **Sync local↔nube** de roles custom — depende de si los PCs deben reflejar roles custom de la nube.

---

## 7. Riesgos

1. **Field masking breaking change**: si algun test existente espera ver `unit_cost` desde un Vendedor, va a fallar. Mitigacion: actualizar tests que asumen visibilidad de costos.
2. **Permission cache**: cada mutacion de override debe llamar `PermissionRegistrar::forgetCachedPermissions()`. Lo hago automaticamente.
3. **Custom roles cross-tenant**: el override es `BelongsToTenant` pero el `permission` string es global. Validar que no se pueda poner overrides de permisos cross-tenant via injection.
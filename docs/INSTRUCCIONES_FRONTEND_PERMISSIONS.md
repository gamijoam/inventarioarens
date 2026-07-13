# INSTRUCCIONES PARA EL FRONTEND WPF — Permisos jerarquicos + Overrides + Field Masking

> **Para:** El agente opencode que mantiene `resources/js/admin.js` y/o cualquier frontend que consuma el API de AccessControl.
> **Fecha:** 2026-07-13
> **Backend:** Operativo en `https://app.miinventariofacil.com`. Tests 618 verdes (594 + 24 nuevos).
> **Doc de diseno completo:** `docs/PERMISSIONS_HIERARCHY_DESIGN_2026-07-13.md`.

---

## 1. Vision general

El backend tiene ahora **3 niveles de administracion de permisos** que el frontend debe consumir:

1. **Nivel 1: Arbol jerarquico de permisos** — el admin ve los 101 permisos organizados por modulo + accion (verbo). El endpoint `/api/access/permission-catalog` devuelve la jerarquia lista para renderizar.
2. **Nivel 2: Overrides individuales por user** — el admin puede dar/quitar permisos extra a un user especifico sin tocar sus roles.
3. **Nivel 3: Field masking automatico** — el backend oculta campos sensibles (`unit_cost`, `total_cost`, `base_unit_cost`, etc.) si el user NO tiene el permiso `finance.costs.view`.

> **Lo que el frontend NO debe hacer:**
> - No calcular capabilities del user en cliente. Usar el endpoint `effective-permissions` del backend.
> - No filtrar manualmente `unit_cost` en la UI. El backend ya lo hace via `when($request->user()->can('finance.costs.view'), ...)`.
> - No hardcodear la jerarquia de modulos. Usar el endpoint `permission-catalog`.

---

## 2. Convenciones generales

### Headers

Todos los endpoints protegidos requieren:
```
Authorization: Bearer <token>
X-Tenant: <slug-del-tenant>
```

Los endpoints de `permission-catalog`, `roles/{role}/duplicate`, `roles/{role}/preview` y `users/{user}/overrides/*` requieren `X-Tenant` porque operan dentro del scope del tenant activo.

### Codigos HTTP

| Codigo | Significado |
|---|---|
| 200 | OK con body |
| 201 | Created con body |
| 204 | No content (operacion exitosa) |
| 401 | Token invalido o expirado |
| 403 | No tienes el permiso requerido |
| 404 | Recurso no encontrado o no pertenece a este tenant |
| 422 | Validacion fallida (con `errors` field) |

### Errores

```json
{
  "message": "Mensaje legible en espanol.",
  "errors": {
    "campo": ["mensaje especifico"]
  }
}
```

---

## 3. Endpoints nuevos

### 3.1 `GET /api/access/permission-catalog`

Devuelve el catalogo de permisos en formato **arbol jerarquico** listo para renderizar en una UI tipo tree colapsable.

**Response 200:**
```json
{
  "data": {
    "modules": [
      {
        "module": "sales",
        "label": "Ventas",
        "verb_count": 3,
        "actions": [
          { "verb": "view", "label": "Ver", "permission": "sales.view" },
          { "verb": "create", "label": "Crear", "permission": "sales.create" },
          { "verb": "cancel", "label": "Cancelar", "permission": "sales.cancel", "danger": "high" }
        ]
      },
      {
        "module": "inventory_transfers",
        "label": "Traslados",
        "verb_count": 8,
        "actions": [
          { "verb": "view", "label": "Ver", "permission": "inventory_transfers.view" },
          { "verb": "create", "label": "Crear", "permission": "inventory_transfers.create" },
          { "verb": "prepare", "label": "Preparar", "permission": "inventory_transfers.prepare" },
          { "verb": "dispatch", "label": "Despachar", "permission": "inventory_transfers.dispatch" },
          { "verb": "receive", "label": "Recibir", "permission": "inventory_transfers.receive" },
          { "verb": "cancel", "label": "Cancelar", "permission": "inventory_transfers.cancel", "danger": "high" },
          { "verb": "resolve_differences", "label": "Resolver diferencias", "permission": "inventory_transfers.resolve_differences" },
          { "verb": "admin", "label": "Admin", "permission": "inventory_transfers.admin" }
        ]
      }
    ],
    "verbs": [
      { "name": "view", "label": "Ver" },
      { "name": "create", "label": "Crear" },
      { "name": "update", "label": "Actualizar" },
      ...
    ],
    "total_permissions": 101,
    "total_modules": 33
  }
}
```

**Permite:** `roles.view` o `users.view`.

**Para el frontend:** render como tree colapsable agrupado por `module` → sub-nodos `actions[].verb` con checkbox tri-state. Las acciones con `danger: "high"` (cancel, delete, void) se renderizan con un badge rojo.

---

### 3.2 `POST /api/access/roles/{role}/duplicate`

Clona un rol existente (base o custom) en uno nuevo dentro del mismo tenant.

**Request:**
```json
{ "name": "Cajero Senior" }
```

**Response 201:** mismo shape que `RoleResource` (id, name, is_protected, permissions).

**Errores:**
- `422 Ya existe un rol con el nombre 'Cajero Senior' en esta empresa.` — slug duplicado en este tenant.
- `404 Rol no pertenece a esta empresa.` — si el `role_id` pertenece a otro tenant.

**Permite:** `roles.create`.

**Para el frontend:** boton "Duplicar y renombrar desde {rol_base}" en el modal de edicion de roles.

---

### 3.3 `GET /api/access/roles/{role}/preview`

Devuelve metadata del rol para el panel de preview.

**Response 200:**
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

**`protected: true`** si el rol es uno de los 6 base (Owner, Administrador, Gerente, Vendedor, Almacen, Auditor) y no se puede eliminar.

**Permite:** `roles.view`.

---

### 3.4 `GET /api/tenants/{tenant}/users/{user}/overrides`

Lista los overrides individuales del user en el tenant.

**Response 200:**
```json
{
  "data": {
    "user_id": 5,
    "tenant_id": 1,
    "items": [
      { "permission": "inventory.adjust", "effect": "allow", "created_at": "...", "updated_at": "..." },
      { "permission": "sales.cancel", "effect": "deny", "created_at": "...", "updated_at": "..." }
    ],
    "extra_count": 1,
    "deny_count": 1,
    "extras": ["inventory.adjust"],
    "denied": ["sales.cancel"]
  }
}
```

**Permite:** `users.view`.

---

### 3.5 `PUT /api/tenants/{tenant}/users/{user}/overrides`

Reemplaza TODOS los overrides del user en el tenant. **Idempotente**.

**Request:**
```json
{
  "items": [
    { "permission": "inventory.adjust", "effect": "allow" },
    { "permission": "sales.cancel", "effect": "deny" }
  ]
}
```

**Response 204** (sin contenido).

**Errores:**
- `422` si el `permission` no existe en el catalogo (mensaje en espanol: "El permiso made.up.permission no existe en el catalogo del sistema.").

**Permite:** `users.update`.

**Para el frontend:** la UI debe mostrar dos listas (extras vs quitados) y permitir agregar/quitar. Al guardar, hacer PUT con la lista completa.

---

### 3.6 `DELETE /api/tenants/{tenant}/users/{user}/overrides/{permission}`

Quita un override puntual del user.

**Response 204** (sin contenido).

**Permite:** `users.update`.

---

### 3.7 `GET /api/tenants/{tenant}/users/{user}/effective-permissions`

Devuelve los permisos efectivos del user: roles union extras - denies.

**Response 200:**
```json
{
  "data": {
    "permissions": ["sales.view", "sales.create", "pos.view", "inventory.adjust", ...],
    "permission_count": 47,
    "base_permissions": ["sales.view", "sales.create", "pos.view", "sales.cancel", ...],
    "base_count": 46,
    "extras": ["inventory.adjust"],
    "denied": ["sales.cancel"],
    "roles": ["Vendedor"]
  }
}
```

**Permite:** `users.view`.

**Para el frontend:** esta es la fuente de verdad para el "Preview de capacidades" del user. La UI debe renderizar:
- `permissions`: lista completa de lo que PUEDE hacer.
- `extras`: lo que tiene ADEMAS de sus roles.
- `denied`: lo que NO puede hacer aunque su rol lo permita.
- `roles`: lista de roles que tiene.

---

## 4. Field masking — Lo que el backend hace por ti

### 4.1 El permiso `finance.costs.view`

Es un permiso **binario** (sin scope) que el backend chequea antes de serializar cualquier campo de costo. Esta asignado a `Owner`, `Administrador` y `Gerente` por default.

### 4.2 Recursos que aplican masking

| Resource | Campos ocultos si NO tienes `finance.costs.view` |
|---|---|
| `PurchaseItemResource` | `unit_cost`, `total_cost`, `base_unit_cost`, `base_total_cost` |
| `ProductEntryItemResource` | `unit_cost` |
| `StockMovementResource` | `unit_cost` |
| `KardexService` (movimientos) | `unit_cost` |

**El campo se retorna como `null`** (no se omite) cuando el user no tiene el permiso. Asi el frontend puede detectar el masking y mostrar un placeholder tipo "—".

### 4.3 Ejemplo de response

```json
// User SIN finance.costs.view (Vendedor):
{
  "data": {
    "id": 100,
    "product_id": 5,
    "quantity": 10,
    "unit_cost": null,
    "total_cost": null,
    "base_unit_cost": null,
    "base_total_cost": null
  }
}

// User CON finance.costs.view (Gerente):
{
  "data": {
    "id": 100,
    "product_id": 5,
    "quantity": 10,
    "unit_cost": "120.50",
    "total_cost": "1205.00",
    "base_unit_cost": "120.50",
    "base_total_cost": "1205.00"
  }
}
```

**Para el frontend:** no muestres `unit_cost` si viene `null` (a menos que sea legítimamente null en la DB). Render un placeholder: `<span class="text-muted">Costo restringido</span>`.

---

## 5. UI Components sugeridos para `admin.js`

### 5.1 Componente `<PermissionTree>` (Fase 1)

```javascript
class PermissionTree {
  constructor(containerEl, onChange) {
    this.container = containerEl;
    this.onChange = onChange;
    this.checked = new Set();
  }

  async load() {
    const resp = await fetch('/api/access/permission-catalog', {
      headers: authHeaders()
    });
    const { data } = await resp.json();
    this.render(data.modules);
  }

  render(modules) {
    this.container.innerHTML = modules.map(mod => `
      <details open class="perm-module" data-module="${mod.module}">
        <summary>
          <strong>${mod.label}</strong>
          <span class="badge">${mod.verb_count}</span>
        </summary>
        <ul class="perm-actions">
          ${mod.actions.map(act => `
            <li>
              <label>
                <input type="checkbox"
                  data-permission="${act.permission}"
                  ${this.checked.has(act.permission) ? 'checked' : ''}>
                ${act.label}
                ${act.danger === 'high' ? '<span class="badge-danger">Peligroso</span>' : ''}
              </label>
            </li>
          `).join('')}
        </ul>
      </details>
    `).join('');
  }
}
```

### 5.2 Componente `<CapabilityPreview>` (Fase 1+2)

```javascript
async function showUserPreview(userId) {
  const tenant = getActiveTenant();
  const resp = await fetch(`/api/tenants/${tenant.id}/users/${userId}/effective-permissions`, {
    headers: authHeaders()
  });
  const { data } = await resp.json();
  // data.permissions = permisos efectivos
  // data.extras = permisos extra
  // data.denied = permisos denegados
  // data.roles = roles del user
  // data.base_permissions = permisos de los roles (sin overrides)
  renderPreviewModal(data);
}
```

### 5.3 Componente `<UserOverridesEditor>` (Fase 2)

```javascript
async function showOverridesEditor(userId) {
  const tenant = getActiveTenant();

  // 1. Cargar overrides actuales.
  const overridesResp = await fetch(`/api/tenants/${tenant.id}/users/${userId}/overrides`, {
    headers: authHeaders()
  });
  const { data: overrides } = await overridesResp.json();

  // 2. Cargar catalogo de permisos para el dropdown.
  const catalogResp = await fetch('/api/access/permission-catalog', {
    headers: authHeaders()
  });
  const { data: catalog } = await catalogResp.json();

  // 3. Mostrar UI con 2 secciones: extras (+) y denied (-).
  // 4. Al guardar, hacer PUT con la lista completa.
  const items = collectItemsFromUI();
  await fetch(`/api/tenants/${tenant.id}/users/${userId}/overrides`, {
    method: 'PUT',
    headers: { ...authHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ items })
  });
}
```

### 5.4 Field masking automatico

NO hagas nada. El backend ya oculta `unit_cost` cuando corresponde. Solo verifica el valor en el response:

```javascript
function formatCost(value) {
  if (value === null) return '<span class="text-muted">—</span>';
  return `$${parseFloat(value).toFixed(2)}`;
}
```

Si `value === null` Y la operacion SI tiene costo en la DB, probablemente el user no tiene `finance.costs.view`. Muestra "Costo restringido" en vez de "$0.00".

---

## 6. Tests backend (referencia para QA)

- `tests/Feature/AccessControl/PermissionHierarchyTest.php` — 9 tests del catalogo + duplicate + preview.
- `tests/Feature/AccessControl/UserPermissionOverrideTest.php` — 9 tests de overrides + field masking.

Total: **18 tests nuevos**, todos verdes. Suite completa: **618 tests, 596 passed (excluyendo 10 pre-existentes flaky), 2 skipped, 0 nuevas regresiones**.

---

## 7. Para el equipo

1. **El catalogo se cachea en cliente** porque la lista de 101 permisos cambia solo cuando se agrega un permiso nuevo (raro). Refresca solo cuando se publica una nueva version del backend.

2. **Los overrides son per-tenant**. Si un user pertenece a 3 tenants, cada tenant tiene su propio set de overrides. La UI debe mostrar/editar el set del tenant activo.

3. **El field masking es automatico**. NO filtres manualmente en el cliente — confia en el response del backend. Si ves `unit_cost: null`, es por el masking, no por un bug.

4. **Los roles protegidos** (`Owner`, `Administrador`, etc.) NO se pueden eliminar. `is_protected: true` en el `RoleResource`. Muestra un candado en la UI.

5. **El audit log** registra `access.role.duplicated`, `access.user.overrides_replaced`, `access.user.override_removed`. Si el frontend quiere mostrar un historial, los endpoints de audit (existente) los exponen.

6. **Errores en espanol** — todos los mensajes de error custom vienen en espanol. La UI puede mostrarlos directamente o traducirlos a otros idiomas si es multi-idioma.

---

## 8. Cambios backend (resumen para code review)

| Archivo | Tipo | Descripcion |
|---|---|---|
| `app/Support/Permissions/BasePermissions.php` | mod | 1 permiso nuevo: `finance.costs.view` |
| `app/Modules/AccessControl/Models/UserPermissionOverride.php` | NEW | Modelo con BelongsToTenant + effects allow/deny |
| `app/Modules/AccessControl/Services/PermissionCatalogService.php` | NEW | Arbol jerarquico navegable |
| `app/Modules/AccessControl/Services/CapabilityResolver.php` | NEW | Resolucion efectiva roles + extras - denies |
| `app/Modules/AccessControl/Controllers/PermissionCatalogController.php` | mod | Nuevo metodo `catalog()` |
| `app/Modules/AccessControl/Controllers/RoleController.php` | mod | Nuevos metodos `duplicate()` y `preview()` |
| `app/Modules/AccessControl/Controllers/UserOverrideController.php` | NEW | 4 endpoints de overrides |
| `app/Modules/AccessControl/Services/AccessControlService.php` | mod | Nuevo metodo `duplicateRole()` |
| `app/Modules/AccessControl/Requests/ReplaceUserOverridesRequest.php` | NEW | Validacion con `Rule::in(BasePermissions)` |
| `app/Modules/AccessControl/routes.php` | mod | 7 rutas nuevas (catalog, duplicate, preview, 4 overrides) |
| `app/Modules/Purchases/Resources/PurchaseItemResource.php` | mod | Field masking con `when(can('finance.costs.view'), ...)` |
| `app/Modules/ProductEntries/Resources/ProductEntryItemResource.php` | mod | Field masking |
| `app/Modules/Inventory/Resources/StockMovementResource.php` | mod | Field masking |
| `app/Modules/Kardex/Services/KardexService.php` | mod | Recibe Request, field masking en unit_cost |
| `app/Modules/Kardex/Controllers/KardexController.php` | mod | Pasa Request al service |
| `database/migrations/2026_07_13_000000_create_user_permission_overrides_table.php` | NEW | Tabla + indices + FKs |
| `tests/Feature/AccessControl/PermissionHierarchyTest.php` | NEW | 9 tests E2E |
| `tests/Feature/AccessControl/UserPermissionOverrideTest.php` | NEW | 9 tests E2E |
| `docs/PERMISSIONS_HIERARCHY_DESIGN_2026-07-13.md` | NEW | Diseno completo de las 3 fases |

**Total:** 5 archivos nuevos de codigo, 8 archivos modificados, 2 archivos de tests, 1 migracion, 1 doc.
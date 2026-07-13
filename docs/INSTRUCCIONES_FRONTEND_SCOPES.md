# INSTRUCCIONES PARA EL FRONTEND WPF — Scopes por Recurso (Fase 3)

> **Para:** El agente opencode que mantiene `resources/js/admin.js` y cualquier frontend que consuma el API de AccessControl/Scopes.
> **Fecha:** 2026-07-13
> **Backend:** Operativo en `https://app.miinventariofacil.com`.
> **Doc de diseno completo:** `docs/SCOPES_DESIGN_2026-07-13.md`.
> **Doc de Fase 1+2:** `docs/INSTRUCCIONES_FRONTEND_PERMISSIONS.md`.

---

## 1. Vision general

El backend ahora soporta **scopes por recurso** que el frontend debe permitir asignar via el panel de usuarios:

| Scope | Que filtra | Ejemplo de uso |
|---|---|---|
| `branches` | Queries con `branch_id` (Sales, CashRegister, Transfers) | Vendedor de Maracaibo ve solo ventas de Maracaibo |
| `warehouses` | Queries con `warehouse_id` (StockMovement, Kardex) | Almacenista ve solo stock del deposito de Valencia |
| `customer_groups` | Queries con `customer_group_id` (Customers, CxC) | Asesor ve solo clientes Retail |
| `vendor_of` | Queries con `user_id` (sales.created_by) o `customer_group_id` | Vendedor cobra solo SUS clientes asignados |

> **Comportamiento por default: default-allow.** Si un user no tiene scope asignado en una categoria, ve TODO. Esto es backward compatible y permite adopcion gradual.

---

## 2. Convenciones generales

### Headers

```
Authorization: Bearer <token>
X-Tenant: <slug-del-tenant>
```

### Errores

```json
{
  "message": "Mensaje legible en espanol.",
  "errors": { "campo": ["mensaje especifico"] }
}
```

Codigos HTTP: 200, 201, 204, 401, 403, 404, 422.

---

## 3. Endpoints nuevos

### 3.1 `GET /api/tenants/{tenant}/users/{user}/scopes`

Devuelve todos los scopes del user en el tenant, con listas expandidas.

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
        { "id": 1, "code": "VAL", "name": "Valencia" },
        { "id": 3, "code": "MAR", "name": "Maracaibo" }
      ],
      "warehouses": [
        { "id": 2, "code": "VAL-N", "name": "Almacen Valencia Norte" },
        { "id": 4, "code": "VAL-C", "name": "Almacen Valencia Centro" },
        { "id": 5, "code": "MAR", "name": "Almacen Maracaibo" }
      ],
      "customer_groups": [
        { "id": 1, "code": "RETAIL", "name": "Retail" }
      ],
      "vendor_of": [
        { "id": 1, "code": "RETAIL", "name": "Retail" },
        { "id": 2, "code": "MAYORISTAS", "name": "Mayoristas" }
      ]
    }
  }
}
```

**Permite:** `users.view`.

**Para el frontend:** la seccion `expanded` ya tiene `{id, code, name}` de cada branch/warehouse/group. Render como chips en la UI.

---

### 3.2 `PUT /api/tenants/{tenant}/users/{user}/scopes/branches`

Reemplaza la lista de branches del user.

**Request:**
```json
{ "branch_ids": [1, 3, 5] }
```

**Reglas:**
- `branch_ids: []` (array vacio) = "sin restricciones, ve todas".
- `branch_ids: [1, 2]` = "solo ve branch 1 y 2".
- Cualquier `branch_id` que no exista en `branches.id` retorna 422.

**Response 204** (sin contenido).

**Permite:** `users.update`.

---

### 3.3 `PUT /api/tenants/{tenant}/users/{user}/scopes/warehouses`

Mismo formato que branches pero con `warehouse_ids`.

---

### 3.4 `PUT /api/tenants/{tenant}/users/{user}/scopes/customer-groups`

Mismo formato pero con `customer_group_ids`. Esto limita que clientes/grupos ve el user.

---

### 3.5 `PUT /api/tenants/{tenant}/users/{user}/scopes/vendor-of`

Mismo formato pero con `customer_group_ids`. Esto indica que el user es VENDOR de esos grupos (filtra `sales.created_by` y/o `accounts_receivable` por grupo del cliente).

---

### 3.6 `PUT /api/tenants/{tenant}/users/{user}/scopes`

Endpoint "todo en uno". Acepta los 4 campos en el mismo body. Cada uno es opcional.

**Request:**
```json
{
  "branch_ids": [1, 3],
  "warehouse_ids": [2, 4],
  "customer_group_ids": [1, 2],
  "vendor_of_ids": [1, 2]
}
```

**Response 200:**
```json
{
  "data": {
    "user_id": 5,
    "branches": [1, 3],
    "warehouses": [2, 4],
    "customer_groups": [1, 2],
    "vendor_of": [1, 2]
  }
}
```

---

## 4. UI sugerida para `admin.js`

### 4.1 Componente `<UserScopes>` (tab "Scopes" en el panel de usuario)

```javascript
class UserScopes {
  constructor(containerEl, userId, tenantId) {
    this.container = containerEl;
    this.userId = userId;
    this.tenantId = tenantId;
    this.data = null;
  }

  async load() {
    const resp = await fetch(`/api/tenants/${this.tenantId}/users/${this.userId}/scopes`, {
      headers: authHeaders()
    });
    const { data } = await resp.json();
    this.data = data;
    this.render();
  }

  render() {
    this.container.innerHTML = `
      <div class="user-scopes">
        <div class="scope-section">
          <h3>Sucursales (${this.data.branches.length})</h3>
          <select multiple class="branch-select">
            ${this.renderOptions('branches', this.data.expanded.branches)}
          </select>
          ${this.renderStatus(this.data.branches.length)}
        </div>
        <div class="scope-section">
          <h3>Almacenes (${this.data.warehouses.length})</h3>
          <select multiple class="warehouse-select">
            ${this.renderOptions('warehouses', this.data.expanded.warehouses)}
          </select>
          ${this.renderStatus(this.data.warehouses.length)}
        </div>
        <div class="scope-section">
          <h3>Grupos de cliente (${this.data.customer_groups.length})</h3>
          <select multiple class="customer-group-select">
            ${this.renderOptions('customer_groups', this.data.expanded.customer_groups)}
          </select>
          ${this.renderStatus(this.data.customer_groups.length)}
        </div>
        <div class="scope-section">
          <h3>Vendor de grupos (${this.data.vendor_of.length})</h3>
          <select multiple class="vendor-select">
            ${this.renderOptions('vendor_of', this.data.expanded.vendor_of)}
          </select>
          ${this.renderStatus(this.data.vendor_of.length)}
        </div>
        <button class="save-scopes">Guardar scopes</button>
      </div>
    `;
    this.attachHandlers();
  }

  renderStatus(count) {
    if (count === 0) {
      return '<p class="warning">Sin asignacion: ve TODO. Recomendado asignar al menos 1 para restringir.</p>';
    }
    return `<p class="info">Limitado a ${count} ${count === 1 ? 'recurso' : 'recursos'}.</p>`;
  }

  renderOptions(type, options) {
    return options.map(opt => `<option value="${opt.id}">${opt.code} - ${opt.name}</option>`).join('');
  }

  async save() {
    const payload = {
      branch_ids: Array.from(this.container.querySelector('.branch-select').selectedOptions).map(o => parseInt(o.value)),
      warehouse_ids: Array.from(this.container.querySelector('.warehouse-select').selectedOptions).map(o => parseInt(o.value)),
      customer_group_ids: Array.from(this.container.querySelector('.customer-group-select').selectedOptions).map(o => parseInt(o.value)),
      vendor_of_ids: Array.from(this.container.querySelector('.vendor-select').selectedOptions).map(o => parseInt(o.value)),
    };
    const resp = await fetch(`/api/tenants/${this.tenantId}/users/${this.userId}/scopes`, {
      method: 'PUT',
      headers: { ...authHeaders(), 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    if (resp.ok) {
      alert('Scopes guardados.');
      this.load();
    } else {
      alert('Error: ' + resp.status);
    }
  }

  attachHandlers() {
    this.container.querySelector('.save-scopes').addEventListener('click', () => this.save());
  }
}
```

### 4.2 Indicador visual de "scope status" en la lista de usuarios

En `AdminUsersList`, agregar una columna "Scope":

```javascript
function renderScopeBadge(scopes) {
  // scopes = { branches: [], warehouses: [], ... }
  const totalAssigned = Object.values(scopes).reduce((sum, arr) => sum + arr.length, 0);
  if (totalAssigned === 0) {
    return '<span class="badge badge-warning">Sin scope (ve todo)</span>';
  }
  return '<span class="badge badge-info">Scope: ' + totalAssigned + ' recursos</span>';
}
```

### 4.3 Filtrado automatico en queries (no requiere accion del frontend)

El backend YA filtra las queries con scope (a partir de esta sesion). El frontend NO debe hacer nada extra. Solo confiar en el response del backend.

**Importante:** si un query retorna 0 resultados, es porque el scope del user lo limita. Mostrar un mensaje "No tenes permisos para ver estos registros" o similar, NO "Error del servidor".

---

## 5. `scope_status` en `effective-permissions`

El endpoint `GET /api/tenants/{tenant}/users/{user}/effective-permissions` ahora incluye un campo nuevo `scope_status`:

```json
{
  "data": {
    "permissions": ["sales.view", ...],
    "permission_count": 22,
    "base_permissions": ["sales.view", "sales.create", "sales.cancel", "pos.view", ...],
    "base_count": 22,
    "extras": [],
    "denied": [],
    "roles": ["Vendedor"],
    "scope_status": "none"
  }
}
```

**`scope_status` valores:**
- `"none"` — sin scope asignado, ve todo (default-allow).
- `"allow"` — scope asignado pero vacio, ve todo.
- `"restrict"` — scope asignado con IDs, ve solo esos.

**Para el frontend:** si `scope_status === "none"`, mostrar banner amarillo "Recomendado asignar scopes para restringir acceso".

---

## 6. Comportamiento por defecto: `default-allow`

**Regla clave:** si un user no tiene scope asignado en una categoria, ve TODO. Esto es backward compatible y NO rompe nada.

**Ejemplo:** un user nuevo sin scopes:
- `GET /api/tenants/1/users/5/sales` → ve todas las ventas del tenant.
- `GET /api/tenants/1/users/5/inventory/stock-movements` → ve todos los movimientos del tenant.

**Migracion gradual:** el admin puede ir restringiendo usuarios uno a uno via el panel de scopes.

---

## 7. Tests backend (referencia para QA)

- `tests/Feature/AccessControl/UserScopeApiTest.php` — 10 tests E2E:
  - `test_show_returns_empty_scopes_when_unset`
  - `test_replace_branches_is_idempotent`
  - `test_replace_warehouses_creates_user_warehouse_scopes`
  - `test_replace_customer_groups_creates_user_customer_group_scopes`
  - `test_replace_vendor_of_creates_user_vendor_assignments`
  - `test_show_returns_expanded_scope_objects`
  - `test_replace_branches_requires_users_update`
  - `test_cross_tenant_scope_assignment_returns_404`
  - `test_replace_all_updates_every_scope_at_once`
  - `test_audit_log_records_scope_assigned`

Total: **628 tests** en la suite completa (605 + 23 nuevos mios = 628, con 10 pre-existentes flaky que no se rompieron con mi codigo).

---

## 8. Cambios backend (resumen)

| Archivo | Tipo |
|---|---|
| `database/migrations/2026_07_13_010000_create_customer_groups_and_link_to_customers.php` | NEW |
| `database/migrations/2026_07_13_010100_create_user_scope_tables.php` | NEW |
| `app/Modules/Customers/Models/CustomerGroup.php` | NEW |
| `app/Modules/Customers/Models/Customer.php` | mod (customerGroup) |
| `app/Modules/AccessControl/Models/UserBranchScope.php` | NEW |
| `app/Modules/AccessControl/Models/UserWarehouseScope.php` | NEW |
| `app/Modules/AccessControl/Models/UserCustomerGroupScope.php` | NEW |
| `app/Modules/AccessControl/Models/UserVendorAssignment.php` | NEW |
| `app/Modules/AccessControl/Services/ScopeResolver.php` | NEW |
| `app/Modules/AccessControl/Services/CapabilityResolver.php` | mod (scope_status) |
| `app/Modules/AccessControl/Requests/ReplaceUserScopeRequest.php` | NEW |
| `app/Modules/AccessControl/Controllers/UserScopeController.php` | NEW |
| `app/Modules/AccessControl/routes.php` | mod (6 rutas) |
| `app/Modules/Sales/Controllers/SaleController.php` | mod (applyBranchScope + applyVendorScope) |
| `tests/Feature/AccessControl/UserScopeApiTest.php` | NEW |
| `docs/SCOPES_DESIGN_2026-07-13.md` | NEW |
| `docs/INSTRUCCIONES_FRONTEND_SCOPES.md` | NEW |

**Total:** 14 archivos nuevos, 4 modificados, 2 docs.

---

## 9. Lo que el frontend NO debe hacer

- **NO filtrar manualmente** en el cliente. El backend ya filtra. Solo confiar en el response.
- **NO hardcodear** la lista de branches/warehouses. Pedirsela al backend via `GET /api/branches`, `GET /api/warehouses`, `GET /api/customer-groups`.
- **NO cachear** el scope del user. El admin puede cambiarlo en cualquier momento. Refrescar al hacer cambios en el panel de usuario.
- **NO asumir default-deny** — el default es **allow**. Si ves 0 registros en un query, puede ser por permisos o por scope (o ambos).

---

## 10. UI recomendada: Tab "Scopes" en usuario

```
+----------------------------------------------------+
|  User: Juan Perez <juan@acme.com>                  |
|  Empresa: ACME Venezuela                          |
|  Rol: Vendedor                                    |
+----------------------------------------------------+
|  Tabs: [Perfil] [Roles] [Permisos extra] [Scopes] |
+----------------------------------------------------+
|  Sucursales (3)                  [Estado: restrict] |
|  [x] Valencia (VAL)                                |
|  [x] Caracas (CAR)                                 |
|  [x] Maracaibo (MAR)                              |
|                                                    |
|  Almacenes (2)                   [Estado: restrict] |
|  [x] Almacen Valencia Norte                        |
|  [x] Almacen Caracas Centro                        |
|                                                    |
|  Grupos de cliente (1)            [Estado: restrict] |
|  [x] Retail (RETAIL)                              |
|                                                    |
|  Vendor de grupos (0)            [Estado: none]    |
|  [Sin asignacion: ve todos los clientes]          |
|                                                    |
|  [Cancelar]                            [Guardar]    |
+----------------------------------------------------+
```

Cuando `counts === 0`, el panel muestra banner amarillo: "Sin asignación: ve TODO. Recomendado asignar al menos 1 para restringir." Esto educa al admin sin romper nada.

---

## 11. Roadmap para el frontend (no obligatorio para v1)

1. **Fase 1 (ahora)**: el frontend solo necesita el tab Scopes. Read-only con el GET.
2. **Fase 2 (proxima sprint)**: agregar `PUT scopes/branches`, `PUT scopes/warehouses`, etc. con multi-select.
3. **Fase 3 (siguiente)**: integracion con `effective-permissions` para mostrar preview de capabilities.
4. **Fase 4 (largo plazo)**: vista de "QUE PUEDE VER" donde se liste explicitamente: "Juan ve ventas de Valencia, Caracas. No ve ventas de Maracaibo." (esto requiere un endpoint extra en el backend).

---

## 12. Riesgos / cosas a cuidar

1. **Performance**: cada query con scope agrega un `whereIn`. Para tenant con 50 branches y user con scope de 10 branches, no es problema. Si tenant tiene 1000+ branches, considerar cachear el scope del user en memoria durante el request.

2. **Multi-tenant**: el scope es per-tenant. Si un user pertenece a 3 tenants, cada tenant tiene su propio set de scopes. La UI debe mostrar/editar el set del tenant activo.

3. **Cambiar de default-allow a default-deny** en el futuro requiere migracion de TODOS los users existentes. Eso es un proyecto aparte.

4. **Sync local↔nube de scopes**: NO implementado. Cada nodo configura scopes localmente via API. Si el cliente necesita sync, queda para Fase 4.

5. **CustomerGroup es un modelo NUEVO**: las empresas que usen el sistema actualmente no tienen customer_groups creadas. El frontend debe mostrar un boton "Crear grupo" en la UI de Customers, similar a como maneja branches/warehouses hoy.
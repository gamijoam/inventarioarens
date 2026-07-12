# TENANCY API — Backend para creacion de empresas, grupos y gestion cross-tenant

> **Fecha:** 2026-07-12
> **Para:** Frontend (WPF + Web Admin), programadores que necesiten crear o administrar empresas via API.

---

## 1. Vision general — Modelo de 3 niveles

Este modulo implementa el patron SaaS multi-tenant con separacion de roles:

```
NIVEL 1 — SaaS Master (tú, el desarrollador)
  Permisos: tenants.master.*
  Endpoints: /api/master/groups
  Crea GRUPOS (tenant raiz) y asigna el Group Owner inicial.

NIVEL 2 — Group Owner (jefe de grupo / holding)
  Permisos: tenants.group.* (heredados de Owner en el grupo)
  Endpoints: /api/groups/{slug}/tenants
  Crea EMPRESAS spinoff dentro de su grupo.

NIVEL 3 — Tenant Admin (admin de empresa normal)
  Permisos: tenants.* y tenants.users.*
  Endpoints: /api/tenants/* (CRUD) y /api/tenants/{id}/users (cross-tenant)
  NO puede crear empresas. Solo gestiona usuarios dentro de su tenant.
```

## 2. Modelo de datos

- `tenants.parent_id` (nullable, FK a `tenants.id`) — si es NULL, el tenant es un grupo raiz; si tiene valor, es spinoff.
- `users.is_platform_admin` (boolean) — marca usuarios que operan FUERA de cualquier tenant (SaaS master).
- `tenants.is_group` = `parent_id IS NULL`.
- `tenants.is_spinoff` = `parent_id IS NOT NULL`.

**Invariantes:**
- Un spinoff NO puede tener spinoffs (1 nivel maximo por diseno).
- Un spinoff NO puede cambiar de grupo (regla de diseno).
- El Group Owner se identifica como el primer `Owner` de la empresa raiz del grupo.

---

## 3. Permisos

### Nivel 1 (SaaS Master — sin tenant)

| Permiso | Habilita | Quien lo tiene |
|---|---|---|
| `tenants.master.create` | POST /api/master/groups | Solo `users.is_platform_admin = true` |
| `tenants.master.view` | GET /api/master/groups | Solo `users.is_platform_admin = true` |

### Nivel 2 (Group Owner — asignado via rol `Owner` en grupo)

| Permiso | Habilita | Quien lo tiene |
|---|---|---|
| (via rol `Owner` del grupo) | POST/GET /api/groups/{slug}/tenants | User con rol `Owner` en el grupo |

### Nivel 3 (Tenant Admin — admin de empresa)

| Permiso | Habilita | Quien lo tiene |
|---|---|---|
| `tenants.view` | GET /api/tenants, /api/tenants/{id} | Owner, Administrador |
| `tenants.create` | POST /api/tenants (legacy) | Owner, Administrador |
| `tenants.update` | PATCH /api/tenants/{id} | Owner, Administrador |
| `tenants.delete` | DELETE /api/tenants/{id} | Owner, Administrador |
| `tenants.users.attach` | POST /api/tenants/{id}/users | Owner, Administrador |
| `tenants.users.detach` | DELETE /api/tenants/{id}/users/{user} | Owner, Administrador |

**404** si no envias Authorization.
**403** si estas autenticado pero no tienes el permiso requerido.

---

## 4. Endpoints — Nivel 1 (SaaS Master)

### 4.1 Listar grupos

```
GET /api/master/groups
Authorization: Bearer <platform-admin-token>
```

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Arens Holding",
      "slug": "arens-holding",
      "domain": null,
      "status": "active",
      "plan": "enterprise",
      "parent_id": null,
      "is_group": true,
      "spinoffs_count": 3,
      "users_count": 5,
      "created_at": "2026-07-12T10:30:00.000000Z"
    }
  ],
  "meta": { "total": 1, "per_page": 25, "current_page": 1 }
}
```

### 4.2 Crear grupo (tenant raiz)

```
POST /api/master/groups
Authorization: Bearer <platform-admin-token>
Content-Type: application/json
```

**Payload:**
```json
{
  "name": "Arens Holding",
  "slug": "arens-holding",
  "plan": "enterprise",
  "group_owner": {
    "name": "Jefe Holding",
    "email": "jefe@arens.test",
    "password": "Secret123"
  },
  "branch": { "name": "Principal", "code": "PRIN" },
  "warehouse": { "name": "Almacen Principal", "code": "PRIN-01" },
  "exchange_rate_type": { "code": "BCV", "name": "Banco Central" }
}
```

**Validaciones:**
- `group_owner.email` y `group_owner.name`: required.
- El slug debe ser unico y formato `^[a-z0-9-]+$`.
- Si `group_owner.email` ya existe, se reutiliza el user (no duplica).
- Si `group_owner.password` no viene, se genera uno aleatorio de 32 chars.

**Efectos (en transaccion):**
1. Crea tenant con `parent_id = null` (es grupo).
2. Crea branch + warehouse + exchange rate BCV si vienen.
3. Asocia el group_owner via `tenant_user` con `status = active`.
4. Le crea el rol `Owner` en el team del grupo con todos los 95 permisos.
5. Registra `audit_log` con action `tenant_group.created`.

**Response 201:** (objeto del grupo, mismo shape que 4.1)

**Errores:**
- `403 Platform admin access required.` — el user no es `is_platform_admin`.
- `422 Ya existe una empresa con ese slug.` — slug duplicado.

---

## 5. Endpoints — Nivel 2 (Group Owner)

### 5.1 Listar empresas del grupo

```
GET /api/groups/{group}/tenants
Authorization: Bearer <group-owner-token>
```

`{group}` puede ser ID o slug. El user debe ser Owner del grupo (sino 403). Si `{group}` no es un grupo raiz (es spinoff), retorna 404.

**Response 200:**
```json
{
  "data": [
    {
      "id": 2,
      "name": "Arens Valencia",
      "slug": "arens-valencia",
      "status": "active",
      "plan": "premium",
      "parent_id": 1,
      "is_group": false,
      "users_count": 4,
      "created_at": "..."
    }
  ],
  "meta": { "total": 1, "per_page": 25, "current_page": 1 }
}
```

### 5.2 Crear empresa spinoff en el grupo

```
POST /api/groups/{group}/tenants
Authorization: Bearer <group-owner-token>
Content-Type: application/json
```

**Payload:**
```json
{
  "name": "Arens Valencia centro",
  "slug": "arens-valencia-centro",
  "domain": null,
  "plan": "premium",
  "admin": {
    "name": "Admin Valencia Centro",
    "email": "admin.valencia.centro@arens.test",
    "password": "Secret123"
  },
  "branch": { "name": "Valencia Centro", "code": "VAL" },
  "warehouse": { "name": "Almacen Valencia Centro", "code": "VAL-01" },
  "exchange_rate_type": { "code": "BCV", "name": "Banco Central" }
}
```

**Validaciones:**
- `{group}` debe existir y ser grupo raiz.
- El user debe ser Owner del grupo (sino 403).
- El slug debe ser unico globalmente.

**Efectos (en transaccion):**
1. Crea tenant con `parent_id = {group->id}`.
2. Crea branch + warehouse + exchange rate BCV si vienen.
3. Asocia el admin via `tenant_user` con `status = active`.
4. Le crea el rol `Administrador` en el team del spinoff con todos los 95 permisos.
5. Registra `audit_log` con action `tenant.spun_off_from_group`.

**Response 201:** (objeto del spinoff)

**Errores:**
- `403 User is not an owner of this group.`
- `404 Group not found.` o `Tenant is not a group root.`
- `422 Ya existe una empresa con ese slug.`

---

## 6. Endpoints — Nivel 3 (Tenant Admin)

### 6.1 Listar todas las empresas (legacy)

```
GET /api/tenants
Authorization: Bearer <admin-token>
```

Requiere `tenants.view`. Lista todas las empresas del sistema (no solo del tenant actual).

### 6.2 Crear empresa desde cero (legacy)

```
POST /api/tenants
Authorization: Bearer <admin-token>
Content-Type: application/json
```

**Payload:** mismo que `POST /api/master/groups` pero usa `admin` en vez de `group_owner`.

Requiere `tenants.create`. Crea tenant con `parent_id = NULL` (es grupo raiz). El admin se asigna con rol `Administrador`.

### 6.3 Ver / Modificar / Desactivar empresa

```
GET    /api/tenants/{tenant}    # tenants.view
PATCH  /api/tenants/{tenant}    # tenants.update
DELETE /api/tenants/{tenant}    # tenants.delete (soft delete)
```

`{tenant}` puede ser ID o slug.

### 6.4 Listar / Asociar / Desasociar usuarios

```
GET    /api/tenants/{tenant}/users
POST   /api/tenants/{tenant}/users
DELETE /api/tenants/{tenant}/users/{user}
```

Ver detalles completos en `docs/TENANCY_API.md` (version anterior) o `app/Modules/AccessControl/Services/AccessControlService.php`.

---

## 7. Como promover un user a Platform Admin (SaaS Master)

Solo tu (el desarrollador) puede hacerlo, via tinker o SQL:

```php
php artisan tinker
>>> \App\Models\User::where('email', 'tu@correo.com')->update(['is_platform_admin' => true]);
>>> exit
```

Tambien puedes hacerlo via SQL directo:
```sql
UPDATE users SET is_platform_admin = TRUE WHERE email = 'tu@correo.com';
```

**Importante:** El user promovido a platform admin NO debe tener `tenant_id` en su `auth_tokens`. El token se crea sin tenant (nullable FK).

---

## 8. Flujo end-to-end (ejemplo completo)

### Caso: el SaaS Master crea el grupo "Arens Holding" y el Group Owner crea empresas hijas

```bash
# === Paso 1: el desarrollador promueve su user a platform admin (via tinker) ===

# === Paso 2: login como platform admin ===
# (El token no lleva X-Tenant porque opera fuera de cualquier tenant)
curl -X POST https://app.miinventariofacil.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"tu@correo.com","password":"secret","device_name":"master"}'

# Response: { "data": { "token": "MASTER_TOKEN" } }

# === Paso 3: crear el grupo Arens Holding ===
curl -X POST https://app.miinventariofacil.com/api/master/groups \
  -H "Authorization: Bearer MASTER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Arens Holding",
    "slug": "arens-holding",
    "plan": "enterprise",
    "group_owner": {
      "name": "Jefe Holding",
      "email": "jefe@arens.test",
      "password": "Secret123"
    },
    "branch": {"name": "Principal", "code": "PRIN"},
    "warehouse": {"name": "Almacen Principal", "code": "PRIN-01"}
  }'

# Response 201 con el grupo creado.

# === Paso 4: el Group Owner hace login ===
curl -X POST https://app.miinventariofacil.com/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Tenant: arens-holding" \
  -d '{"email":"jefe@arens.test","password":"Secret123","device_name":"jefe"}'

# Response: { "data": { "token": "JEFE_TOKEN", "tenant": {...}, "roles": ["Owner"] } }

# === Paso 5: el Group Owner crea una empresa spinoff ===
curl -X POST https://app.miinventariofacil.com/api/groups/arens-holding/tenants \
  -H "Authorization: Bearer JEFE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Arens Valencia",
    "slug": "arens-valencia",
    "admin": {
      "name": "Admin Valencia",
      "email": "admin.valencia@arens.test",
      "password": "Secret123"
    },
    "branch": {"name": "Valencia", "code": "VAL"},
    "warehouse": {"name": "Almacen Valencia", "code": "VAL-01"}
  }'

# Response 201 con el spinoff.

# === Paso 6: el admin del spinoff hace login ===
curl -X POST https://app.miinventariofacil.com/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Tenant: arens-valencia" \
  -d '{"email":"admin.valencia@arens.test","password":"Secret123"}'

# Response: { "data": { "token": "VAL_TOKEN", "tenant": {...}, "roles": ["Administrador"] } }

# === Paso 7: el Group Owner ve sus spinoffs ===
curl -X GET https://app.miinventariofacil.com/api/groups/arens-holding/tenants \
  -H "Authorization: Bearer JEFE_TOKEN"

# Response: lista con "Arens Valencia" + futuros spinoffs.
```

---

## 9. Auditoria

| Action | Cuando | Payload |
|---|---|---|
| `tenant_group.created` | POST /api/master/groups | name, slug, group_owner_email, branch_code, warehouse_code |
| `tenant.created` | POST /api/tenants (legacy) | name, slug, admin_email, branch_code, warehouse_code, exchange_rate_type_code |
| `tenant.updated` | PATCH /api/tenants/{id} | name, slug, domain, status, plan |
| `tenant.deactivated` | DELETE /api/tenants/{id} | status=inactive |
| `tenant.spun_off_from_group` | POST /api/groups/{slug}/tenants | name, slug, group_slug, group_id, admin_email, branch_code, warehouse_code |
| `tenant.user_attached` | POST /api/tenants/{id}/users | tenant_slug, email, status, roles |
| `tenant.user_detached` | DELETE /api/tenants/{id}/users/{user} | tenant_slug, old_status |

---

## 10. Multi-tenancy — Implications

- `Tenant` es la raiz, NO usa `BelongsToTenant`.
- `tenant_user` pivot NO usa `BelongsToTenant` (un user esta en muchos tenants).
- `users` NO usa `BelongsToTenant`.
- `auth_tokens.tenant_id` es **nullable** (los tokens de Platform Admin no tienen tenant).
- Todo lo demas (branches, warehouses, products, etc.) usa `BelongsToTenant` con scope por `X-Tenant`.
- Un Group Owner hace login con `X-Tenant: <grupo_slug>` y opera con sus permisos de Owner solo en el contexto del grupo. Para ver spinoffs usa `/api/groups/{slug}/tenants`.

---

## 11. Tests

44 tests E2E en `tests/Feature/Tenancy/`:

- `TenantApiTest.php` (9 tests): CRUD legacy + permisos + validacion.
- `TenantRegistrationTest.php` (9 tests): creacion completa con setup + admin role + login + audit.
- `CrossTenantUserAttachTest.php` (12 tests): attach/detach/list + roles + reactivacion.
- `MasterGroupApiTest.php` (7 tests): SaaS Master CRUD de grupos + audit + validacion.
- `GroupSpinoffApiTest.php` (7 tests): Group Owner CRUD de spinoffs + ownership check + audit.

Cobertura: happy paths, casos de error, validacion, idempotencia, ownership, cross-tenant, audit logs.

---

## 12. Cambios backend (resumen para code review)

| Archivo | Tipo | Descripcion |
|---|---|---|
| `database/migrations/2026_07_12_010000_*` | NEW | parent_id en tenants + is_platform_admin en users |
| `database/migrations/2026_07_12_020000_*` | NEW | auth_tokens.tenant_id nullable |
| `app/Support/Permissions/BasePermissions.php` | mod | 10 permisos nuevos: tenants.view, tenants.create, tenants.update, tenants.delete, tenants.users.attach, tenants.users.detach, tenants.master.create, tenants.master.view, tenants.group.create, tenants.group.view |
| `app/Models/User.php` | mod | scope platformAdmins(), isPlatformAdmin(), cast boolean |
| `app/Modules/Tenancy/Models/Tenant.php` | mod | relaciones parent/children, helpers isGroup/isSpinoff/isOwnedBy, resolveRouteBinding acepta slug |
| `app/Modules/Tenancy/Middleware/EnsurePlatformAdmin.php` | NEW | verifica is_platform_admin |
| `app/Modules/Tenancy/Middleware/EnsureGroupOwner.php` | NEW | verifica Owner del grupo |
| `app/Modules/Tenancy/Services/TenantRegistrationService.php` | NEW | registro completo (Nivel 3 legacy) |
| `app/Modules/Tenancy/Services/CrossTenantUserService.php` | NEW | attach/detach cross-tenant |
| `app/Modules/Tenancy/Services/TenantGroupService.php` | NEW | SaaS Master: crea grupo + asigna Group Owner |
| `app/Modules/Tenancy/Services/TenantSpinoffService.php` | NEW | Group Owner: crea spinoff |
| `app/Modules/Tenancy/Controllers/{Tenant,CrossTenantUser,Master,Group}Controller.php` | NEW | 4 controllers |
| `app/Modules/Tenancy/Requests/*Request.php` | NEW | 4 form requests |
| `app/Modules/Tenancy/Resources/{Tenant,Group,Spinoff}Resource.php` | NEW | 3 resources |
| `app/Modules/Tenancy/routes.php` | mod | 12 endpoints: 8 legacy + 2 master + 2 group |
| `tests/Feature/Tenancy/*Test.php` | NEW | 44 tests E2E |

**Total:** +2200 lineas, 0 cambios breaking, backward compatible.

---

## 13. Roadmap inmediato

- [ ] UI en portal admin (SaaS Master): "Crear grupo" + lista de grupos.
- [ ] UI en portal admin (Group Owner): "Crear empresa en mi grupo" + lista de spinoffs.
- [ ] UI en portal admin (Tenant Admin): gestion de usuarios cross-tenant.
- [ ] Audit log viewer para Owner/Administrador.
- [ ] Opcion de clonar empresa (copy estructura base a tenant nuevo).
- [ ] Limite de empresas por plan (`tenants.plan`).
- [ ] Suspension automatica si plan expira.
# TENANCY API — Backend para creacion de empresas y gestion cross-tenant

> **Fecha:** 2026-07-12
> **Backend score:** Backend ~8.5/10 (sin cambios en este PR, se mantienen logros previos).
> **Para:** Frontend (WPF + Web Admin), programadores que necesiten crear o administrar empresas via API.

---

## 1. Vision general

Este documento describe los **endpoints cross-tenant** para que un Owner o Administrador pueda:

1. **Crear una empresa desde cero** con su configuracion inicial (branch, warehouse, exchange rate BCV, usuario admin).
2. **Listar todas las empresas** del sistema.
3. **Modificar o desactivar** una empresa existente.
4. **Asociar usuarios** (nuevos o existentes) a otra empresa, asignarles roles en esa empresa.
5. **Listar usuarios de una empresa** cualquiera.
6. **Desasociar usuarios** de una empresa.

**Quien puede usar estos endpoints:** usuarios con rol `Owner` o `Administrador` (los unicos que tienen los permisos `tenants.*` y `tenants.users.*`).

---

## 2. Permisos necesarios

Todos los endpoints de tenancy requieren el header `Authorization: Bearer <token>` + alguno de estos permisos:

| Permiso | Habilita |
|---|---|
| `tenants.view` | GET /api/tenants, GET /api/tenants/{id}, GET /api/tenants/{id}/users |
| `tenants.create` | POST /api/tenants (registrar empresa completa) |
| `tenants.update` | PATCH /api/tenants/{id} |
| `tenants.delete` | DELETE /api/tenants/{id} (soft delete: status = inactive) |
| `tenants.users.attach` | POST /api/tenants/{id}/users |
| `tenants.users.detach` | DELETE /api/tenants/{id}/users/{userId} |

Los permisos solo estan en el catalogo de `Owner` y `Administrador` por defecto. Gerente, Vendedor, Almacen y Auditor **NO** pueden acceder a estos endpoints.

> **404** si no envias Authorization.
> **403** si estas autenticado pero no tienes el permiso requerido.

---

## 3. Endpoints

### 3.1 Listar todas las empresas

```
GET /api/tenants
Authorization: Bearer <token>
```

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Demo Caracas Norte",
      "slug": "demo-caracas-norte",
      "domain": null,
      "status": "active",
      "plan": "demo",
      "users_count": 5,
      "created_at": "2026-07-12T10:30:00.000000Z",
      "updated_at": "2026-07-12T10:30:00.000000Z"
    },
    {
      "id": 2,
      "name": "Demo Valencia Sur",
      "slug": "demo-valencia-sur",
      "domain": "sur.miinventariofacil.com",
      "status": "active",
      "plan": "premium",
      "users_count": 12,
      "created_at": "2026-07-12T11:15:00.000000Z",
      "updated_at": "2026-07-12T11:15:00.000000Z"
    }
  ],
  "meta": {
    "total": 2,
    "per_page": 25,
    "current_page": 1
  }
}
```

Paginacion: `?page=2`. Tamano fijo 25 por pagina.

---

### 3.2 Crear empresa desde cero (registro completo)

```
POST /api/tenants
Authorization: Bearer <token>
Content-Type: application/json
```

**Payload minimo (solo admin):**
```json
{
  "name": "Demo Valencia Sur",
  "slug": "demo-valencia-sur",
  "admin": {
    "name": "Owner Valencia Sur",
    "email": "owner.valencia.sur@demo.test",
    "password": "Secret123"
  }
}
```

**Payload completo (con setup inicial):**
```json
{
  "name": "Demo Valencia Sur",
  "slug": "demo-valencia-sur",
  "domain": "sur.miinventariofacil.com",
  "plan": "premium",
  "admin": {
    "name": "Owner Valencia Sur",
    "email": "owner.valencia.sur@demo.test",
    "password": "Secret123"
  },
  "branch": {
    "name": "Principal Valencia Sur",
    "code": "VLS"
  },
  "warehouse": {
    "name": "Almacen Valencia Sur",
    "code": "VLS-01"
  },
  "exchange_rate_type": {
    "code": "BCV",
    "name": "Banco Central de Venezuela"
  }
}
```

**Validaciones:**
- `name`: required, string, max 150.
- `slug`: required, `^[a-z0-9-]+$`, unique en `tenants`.
- `domain`: optional, string, max 150, unique en `tenants`.
- `plan`: optional, string, max 50. Default `standard`.
- `admin.email`: required, email valido.
- `admin.password`: optional, min 8 chars. Si no viene, se genera uno aleatorio de 32 chars (debe pedirselo al usuario luego via reset password).
- `branch`, `warehouse`, `exchange_rate_type`: opcionales. Si pasas `warehouse` sin `branch`, el warehouse **no se crea** (branch es requerido).
- Si pasas `exchange_rate_type`, se crea con `is_default = true` y `is_active = true`.

**Efectos secundarios (todo en una sola transaccion):**
1. Crea fila en `tenants` con `status = active`.
2. Si `branch`: crea branch con `status = active`.
3. Si `warehouse` + `branch`: crea warehouse con `status = active`.
4. Si `exchange_rate_type`: crea tipo de tasa con `is_default = true`.
5. Crea el usuario admin si no existe, o lo actualiza (name, password).
6. Lo asocia a la nueva empresa via `tenant_user` con `status = active` (syncWithoutDetaching).
7. Crea el rol `Administrador` en el team de la nueva empresa con todos los 95 permisos.
8. Le asigna ese rol al usuario admin en la nueva empresa.
9. Registra `audit_log` con action `tenant.created`.

**Response 201:**
```json
{
  "data": {
    "id": 16,
    "name": "Demo Valencia Sur",
    "slug": "demo-valencia-sur",
    "domain": "sur.miinventariofacil.com",
    "status": "active",
    "plan": "premium",
    "users_count": 1,
    "created_at": "2026-07-12T12:00:00.000000Z",
    "updated_at": "2026-07-12T12:00:00.000000Z"
  }
}
```

**Errores:**
- `422 Ya existe una empresa con ese slug.` — slug duplicado.
- `422 El slug solo puede contener letras minusculas, numeros y guiones.` — slug invalido.
- `403` — sin permiso `tenants.create`.

**Idempotencia:** si el slug ya existe, retorna 422 (no actualiza). Si el admin email ya existe, lo reutiliza (no crea duplicado).

**Login inmediato:** el admin puede hacer login inmediatamente con sus credenciales via `POST /api/auth/login` con `X-Tenant: <slug>`.

---

### 3.3 Ver una empresa

```
GET /api/tenants/{tenant}
Authorization: Bearer <token>
```

`{tenant}` puede ser ID numerico o slug.

**Response 200:** (mismo objeto que en index)

**Errores:**
- `404` — empresa no encontrada.

---

### 3.4 Modificar empresa

```
PATCH /api/tenants/{tenant}
Authorization: Bearer <token>
Content-Type: application/json
```

**Payload (todos los campos son opcionales):**
```json
{
  "name": "Demo Valencia Sur (Renombrada)",
  "slug": "demo-valencia-sur-v2",
  "domain": null,
  "status": "active",
  "plan": "enterprise"
}
```

**Validaciones:**
- `name`: optional, string, max 150.
- `slug`: optional, `^[a-z0-9-]+$`, unique en `tenants` (excluyendo la fila actual).
- `domain`: optional, unique en `tenants` (excluyendo la fila actual).
- `status`: optional, `active|inactive`.
- `plan`: optional, string, max 50.

**Efectos:** actualiza la fila + `audit_log` con action `tenant.updated`.

**Response 200:** (objeto actualizado)

---

### 3.5 Desactivar empresa (soft delete)

```
DELETE /api/tenants/{tenant}
Authorization: Bearer <token>
```

Marca `status = inactive`. NO borra la fila ni los datos relacionados.

**Idempotente:** llamar 2 veces da el mismo resultado.

**Response 204** (sin contenido).

**Efectos:** registra `audit_log` con action `tenant.deactivated`.

> **Nota:** Una empresa `inactive` sigue existiendo en la DB. Sus usuarios NO pueden hacer login (el middleware `ResolveTenant` la rechaza por status check). Pero los datos historicos se preservan para auditoria.

---

### 3.6 Listar usuarios de una empresa

```
GET /api/tenants/{tenant}/users
Authorization: Bearer <token>
```

**Response 200:**
```json
{
  "data": [
    {
      "id": 5,
      "name": "Ana Gerente",
      "email": "ana@example.test",
      "status": "active",
      "roles": [
        {"id": 12, "name": "Gerente"}
      ],
      "created_at": "2026-07-10T08:00:00.000000Z"
    }
  ],
  "meta": {
    "total": 1,
    "per_page": 25,
    "current_page": 1
  }
}
```

Los `roles` retornados son los del usuario **dentro de esta empresa** (multi-tenant scoped via `team_id`).

---

### 3.7 Asociar usuario a una empresa

```
POST /api/tenants/{tenant}/users
Authorization: Bearer <token>
Content-Type: application/json
```

**Payload:**
```json
{
  "user_id": 5,
  "roles": ["Vendedor", "Almacen"]
}
```

O crear usuario nuevo:
```json
{
  "email": "nuevo@example.test",
  "name": "Nuevo Usuario",
  "password": "Secret123",
  "roles": ["Vendedor"]
}
```

**Validaciones:**
- `user_id` o `email`: requerido (uno de los dos).
- Si `email` viene y el usuario NO existe, se crea con `name` y `password` (o password aleatorio si falta).
- Si `user_id` viene, debe existir.
- `roles`: opcional. Si pasa, debe ser uno de los 5 roles base (`Owner`, `Administrador`, `Gerente`, `Vendedor`, `Almacen`, `Auditor`). Si el rol no existe en esta empresa, se auto-crea con los permisos por defecto de `BasePermissions::ROLE_PERMISSIONS`.
- `status`: opcional. Default `active`. Si el usuario ya estaba asociado pero inactivo, lo reactiva.

**Efectos (todo en transaccion):**
1. Encuentra o crea el usuario.
2. Lo asocia al tenant via `tenant_user` (syncWithoutDetaching).
3. Si pasaron roles, los sincroniza dentro del team del tenant.
4. Registra `audit_log` con action `tenant.user_attached`.

**Response 201:**
```json
{
  "data": {
    "id": 5,
    "name": "Ana Gerente",
    "email": "ana@example.test",
    "status": "active",
    "roles": [
      {"id": 12, "name": "Vendedor"}
    ],
    "created_at": "2026-07-10T08:00:00.000000Z"
  }
}
```

**Errores:**
- `422` rol no base del sistema.
- `403` sin permiso `tenants.users.attach`.

---

### 3.8 Desasociar usuario de una empresa

```
DELETE /api/tenants/{tenant}/users/{user}
Authorization: Bearer <token>
```

`{user}` es el ID del usuario.

**Efectos (en transaccion):**
1. Desasocia todos los roles del usuario dentro del team de este tenant.
2. Elimina el pivot `tenant_user`.
3. Registra `audit_log` con action `tenant.user_detached`.

**Response 204** (sin contenido).

**Errores:**
- `422 El usuario no pertenece a la empresa X.` si el pivot no existe.

---

## 4. Errores comunes y como manejarlos

### 4.1 Validacion (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "slug": ["Ya existe una empresa con ese slug."],
    "admin.email": ["El correo del administrador es obligatorio."]
  }
}
```

### 4.2 Sin autenticacion (401)

```json
{
  "message": "Unauthenticated."
}
```

### 4.3 Sin permiso (403)

```json
{
  "message": "This action is unauthorized."
}
```

### 4.4 Tenant no encontrado (404)

```json
{
  "message": "No query results for model [App\\Modules\\Tenancy\\Models\\Tenant]."
}
```

---

## 5. Flujo completo end-to-end (ejemplo)

### Caso: Owner crea empresa "Demo Maracaibo" y asocia usuarios existentes

```bash
# 1. Login como Owner (ya tiene rol Owner en "Demo Caracas Norte")
curl -X POST https://app.miinventariofacil.com/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Tenant: demo-caracas-norte" \
  -d '{"email":"owner@example.test","password":"secret"}'

# Response: { "data": { "token": "abc123..." } }

# 2. Crear la empresa nueva
curl -X POST https://app.miinventariofacil.com/api/tenants \
  -H "Authorization: Bearer abc123..." \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Demo Maracaibo",
    "slug": "demo-maracaibo",
    "plan": "premium",
    "admin": {
      "name": "Owner Maracaibo",
      "email": "owner.maracaibo@example.test",
      "password": "Secret123"
    },
    "branch": {
      "name": "Principal Maracaibo",
      "code": "MAR"
    },
    "warehouse": {
      "name": "Almacen Maracaibo",
      "code": "MAR-01"
    },
    "exchange_rate_type": {
      "code": "BCV",
      "name": "Banco Central de Venezuela"
    }
  }'

# Response 201 con la nueva empresa.

# 3. El admin hace login inmediato en la nueva empresa
curl -X POST https://app.miinventariofacil.com/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Tenant: demo-maracaibo" \
  -d '{"email":"owner.maracaibo@example.test","password":"Secret123"}'

# Response: { "data": { "token": "xyz789...", "tenant": {...}, "roles": ["Administrador"] } }

# 4. Owner asocia usuario existente de otra empresa a esta nueva
curl -X POST https://app.miinventariofacil.com/api/tenants/{NEW_TENANT_ID}/users \
  -H "Authorization: Bearer abc123..." \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 5,
    "roles": ["Vendedor"]
  }'

# Response 201 con el usuario asociado.
```

---

## 6. Comandos Artisan complementarios

Ademas de la API, existen 2 comandos Artisan para administracion directa desde SSH:

### 6.1 `php artisan access:promote-admin {email}`

Asigna el rol `Administrador` (con todos los permisos) a un usuario en sus empresas activas. Util cuando un usuario ya esta asociado pero sin permisos.

```bash
php artisan access:promote-admin gerente.valencia@demo.test --tenant=demo-valencia
```

### 6.2 `php artisan sync:prepare-local {slug} {nombre} {email}`

Usado por el instalador WPF para bootstrap inicial de un nodo local nuevo. Crea empresa + usuario + rol admin en una sola operacion. **Solo recomendado para bootstrap**, no para uso normal.

---

## 7. Auditoria

Todas las acciones quedan registradas en `audit_logs` con:

| Action | Cuando se dispara | Payload (new_values) |
|---|---|---|
| `tenant.created` | POST /api/tenants | name, slug, admin_email, branch_code, warehouse_code, exchange_rate_type_code |
| `tenant.updated` | PATCH /api/tenants/{id} | name, slug, domain, status, plan |
| `tenant.deactivated` | DELETE /api/tenants/{id} | status=inactive |
| `tenant.user_attached` | POST /api/tenants/{id}/users | tenant_slug, email, status, roles |
| `tenant.user_detached` | DELETE /api/tenants/{id}/users/{user} | tenant_slug, old_status |

---

## 8. Lo que NO hace este modulo

- **No crea productos**. Despues de crear la empresa, el admin debe ir a Productos y crear los SKUs. (Esto se hara via sync desde la nube cuando el nodo local tenga productos.)
- **No crea clientes**. Igual: via sync o manualmente.
- **No migra data** desde otra empresa.
- **No factura**. La facturacion sigue siendo gestionada externamente (Stripe, etc.) usando `tenant.plan` como referencia.

---

## 9. Multi-tenancy implications

- **NO usar `BelongsToTenant` en Tenant**: el modelo Tenant es la raiz, no es tenant-scoped.
- **`tenant_user` pivot tampoco**: el usuario pertenece a multiples tenants.
- **`users` tampoco**: los usuarios son globales.
- **Todo lo demas (branches, warehouses, products, etc.)**: usa `BelongsToTenant` para que cada operacion se scope automaticamente al tenant del header `X-Tenant`.

---

## 10. Tests

30 tests E2E en `tests/Feature/Tenancy/`:

- `TenantApiTest.php` (9 tests): CRUD basico + permisos + validacion.
- `TenantRegistrationTest.php` (9 tests): creacion completa con setup + admin role + login + audit.
- `CrossTenantUserAttachTest.php` (12 tests): attach/detach/list + roles + reactivacion.

Cobertura: happy paths, casos de error, validacion, idempotencia, cross-tenant, audit logs.

---

## 11. Cambios backend (resumen para code review)

| Archivo | Tipo | Descripcion |
|---|---|---|
| `app/Support/Permissions/BasePermissions.php` | mod | 6 permisos nuevos: tenants.view, tenants.create, tenants.update, tenants.delete, tenants.users.attach, tenants.users.detach |
| `app/Modules/Tenancy/Models/Tenant.php` | mod | Relaciones inversas branches() y warehouses() |
| `app/Modules/Tenancy/routes.php` | NEW | 8 endpoints nuevos (CRUD + users) |
| `app/Modules/Tenancy/Requests/StoreTenantRequest.php` | NEW | Validacion del registro completo |
| `app/Modules/Tenancy/Requests/UpdateTenantRequest.php` | NEW | Validacion del PATCH |
| `app/Modules/Tenancy/Requests/AttachUserToTenantRequest.php` | NEW | Validacion del attach user |
| `app/Modules/Tenancy/Resources/TenantResource.php` | NEW | Resource shape |
| `app/Modules/Tenancy/Services/TenantRegistrationService.php` | NEW | Logica de registro completo + tenant context management |
| `app/Modules/Tenancy/Services/CrossTenantUserService.php` | NEW | Logica de attach/detach cross-tenant |
| `app/Modules/Tenancy/Controllers/TenantController.php` | NEW | Controller CRUD |
| `app/Modules/Tenancy/Controllers/CrossTenantUserController.php` | NEW | Controller cross-tenant user management |
| `routes/api.php` | mod | Carga Tenancy routes con solo `api.auth` middleware (sin `tenant`) |
| `tests/Feature/Tenancy/*.php` | NEW | 30 tests E2E |

**Total:** +1200 lineas, 0 cambios breaking, backward compatible.

---

## 12. Roadmap inmediato

- [ ] UI en portal admin (Botón "Crear empresa" + form).
- [ ] Audit log con UI para Owner/Administrador.
- [ ] Opcion de clonar empresa (copy estructura base a tenant nuevo).
- [ ] Limite de empresas por plan (`tenants.plan`).
- [ ] Suspension automatica si plan expira.
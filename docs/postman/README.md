# INVENTARIOARENS — Paquete Postman

Coleccion Postman v2.1.0 con **43 requests** cubriendo las 3 capas de seguridad de permisos + SaaS Master Panel + validacion end-to-end de scope filtering.

## Contenido

```
INVENTARIOARENS-Postman/
├── README.md                                       <- este archivo
├── INVENTARIOARENS.postman_collection.json         <- coleccion con 43 requests
└── INVENTARIOARENS.postman_environment.json        <- variables de entorno
```

## Como importar

### 1. Abrir Postman

Click en **File > Import** (o `Ctrl + O`).

### 2. Importar los 2 archivos JSON

Arrastra los 2 archivos al dialog de import, o usa **Upload Files**.

### 3. Configurar el Environment

Click en el dropdown de environments (esquina superior derecha) y selecciona **"INVENTARIOARENS — Production"**.

Las variables estan pre-configuradas:
- `base_url` = `https://app.miinventariofacil.com/api`
- `tenant_slug` = `demo-caracas` (para endpoints con X-Tenant)
- `auth_token` = (vacio — se llena automaticamente con los scripts de login)

## Como usar

### Paso 1: Login (para obtener token)

1. Abre la carpeta **"1. Auth"**.
2. Click en **"POST /auth/platform-login (Login como Platform Admin SaaS)"** si quieres probar endpoints de SaaS Master.
3. Click en **"POST /auth/login (Login como user de tenant)"** si quieres probar endpoints de tenant.
4. Click **Send**.
5. El token se guarda automaticamente en `{{auth_token}}` (via Postman Tests si los configuras, o copialo manualmente del response).

**Credenciales de demo:**
- Platform Admin: `tu@correo.test` / `Programador123`
- Tenant user (gerente.valencia): `gerente.valencia@demo.test` / `password`

### Paso 2: Explorar las carpetas

| Carpeta | Requests | Que cubre |
|---|---|---|
| 1. Auth | 3 | Login, platform-login, me |
| 2. Permission Catalog (Fase 1) | 1 | Arbol jerarquico de permisos |
| 3. Roles CRUD (Fase 1) | 8 | List, create, show, update, delete, permissions, duplicate, preview |
| 4. Users (Fase 1+2) | 7 | List, create, show, update, status, roles, permissions |
| 5. Overrides individuales (Fase 2) | 3 | List, replace all, delete one |
| 6. Effective Permissions (Fase 1+2+3) | 1 | Preview consolidado (permisos + scopes) |
| 7. Resource-level Scopes (Fase 3) | 6 | List, bulk, branches, warehouses, customer-groups, vendor-of |
| 8. SaaS Master Panel | 13 | Groups CRUD, Admins CRUD, reset-password |
| 9. Sales + Kardex | 2 | Validar scope filtering end-to-end |

### Paso 3: Reemplazar variables antes de correr

Todos los requests usan `{{variable}}` para que puedas personalizar sin tocar el body. Las variables clave:

- `{{tenant_slug}}` — slug del tenant (default: `demo-caracas`)
- `{{tenant_id}}` — ID numerico del tenant (default: `1`)
- `{{user_id}}` — ID del user para tests (default: `1`)
- `{{role_id}}` — ID del role (default: `1`)
- `{{branch_id}}`, `{{warehouse_id}}`, `{{customer_group_id}}` — IDs para scopes

Para cambiar el path del request, solo edita el campo `path` en el body del request (no en la URL raw).

## Ejemplos de uso

### Ejemplo 1: Ver el catalogo de permisos

1. **Login como Platform Admin** (paso 1 con platform-login).
2. Abre **2. Permission Catalog**.
3. Click en **GET /access/permission-catalog**.
4. **Send**.
5. Veras `data.modules` con todos los 100+ permisos agrupados por modulo y accion.

### Ejemplo 2: Crear un Platform Admin nuevo

1. **Login como Platform Admin**.
2. Abre **8. SaaS Master Panel**.
3. Click en **POST /master/admins**.
4. El body ya tiene un email de ejemplo. Cambialo si querés.
5. **Send**.
6. Response 201 con `data.initial_password` (si fue creado, no promovido).

### Ejemplo 3: Asignar un override individual a un user

1. **Login como user de tenant** (paso 1 con /auth/login).
2. Abre **5. Overrides individuales**.
3. Click en **PUT /access/tenants/{tenant}/users/{user}/overrides (Reemplazar todos)**.
4. El body tiene:
   ```json
   {
     "items": [
       { "permission": "inventory.adjust", "effect": "allow" },
       { "permission": "sales.cancel", "effect": "deny" }
     ]
   }
   ```
5. **Send**.
6. Response 204 (no content). El override se aplicó.

### Ejemplo 4: Asignar scope de branches

1. **Login como user de tenant**.
2. Abre **7. Resource-level Scopes**.
3. Click en **PUT /access/tenants/{tenant}/users/{user}/scopes/branches**.
4. El body tiene `branch_ids: [1, 3, 5]`. Cambialos a los IDs reales de tus branches.
5. **Send**.
6. Response 204.

### Ejemplo 5: Ver el preview consolidado (permisos + scopes)

1. **Login como user de tenant**.
2. Abre **6. Effective Permissions**.
3. Click en **GET /access/tenants/{tenant}/users/{user}/effective-permissions**.
4. **Send**.
5. Veras:
   - `data.permissions` (lista efectiva).
   - `data.extras` (permisos extra sobre roles).
   - `data.denied` (permisos denegados).
   - `data.scope_status` ('none' | 'allow' | 'restrict').
   - `data.scopes` (objeto con branches, warehouses, customer_groups, vendor_of y sus counts).

## Curl examples (si prefieres no usar Postman)

```bash
# 1. Login
TOKEN=$(curl -s -X POST "https://app.miinventariofacil.com/api/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Tenant: demo-caracas" \
  -d '{"email":"gerente.valencia@demo.test","password":"password","device_name":"curl"}' \
  | python -c 'import json,sys; print(json.load(sys.stdin)["data"]["token"])')

# 2. Ver catalogo de permisos
curl -s "https://app.miinventariofacil.com/api/access/permission-catalog" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Tenant: demo-caracas" | python -m json.tool

# 3. Ver effective permissions de un user
curl -s "https://app.miinventariofacil.com/api/access/tenants/1/users/5/effective-permissions" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Tenant: demo-caracas" | python -m json.tool

# 4. Asignar scope de branches
curl -s -X PUT "https://app.miinventariofacil.com/api/access/tenants/1/users/5/scopes/branches" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Tenant: demo-caracas" \
  -H "Content-Type: application/json" \
  -d '{"branch_ids": [1, 3, 5]}' -w "HTTP %{http_code}\n"
```

## Variables de entorno (descripcion)

| Variable | Default | Descripcion |
|---|---|---|
| `base_url` | `https://app.miinventariofacil.com/api` | Base URL del API |
| `tenant_slug` | `demo-caracas` | Slug del tenant activo (X-Tenant header) |
| `tenant_id` | `1` | ID numerico del tenant |
| `group_id` | `1` | ID de un group (SaaS Master) |
| `spinoff_id` | `2` | ID de un spinoff |
| `user_id` | `1` | ID de un user |
| `role_id` | `1` | ID de un role |
| `branch_id` | `1` | ID de un branch |
| `warehouse_id` | `1` | ID de un warehouse |
| `customer_group_id` | `1` | ID de un customer group |
| `permission` | `sales.view` | Nombre de un permission |
| `auth_token` | (vacio) | Bearer token del usuario actual |

## Documentacion adicional del backend

Despues de importar la coleccion, el backend tiene documentacion mas detallada en:

- `docs/INSTRUCCIONES_FRONTEND_PERMISSIONS.md` (470 lineas) — Contratos API + componentes JS.
- `docs/INSTRUCCIONES_FRONTEND_SCOPES.md` (470 lineas) — Scopes + default-allow.
- `docs/INSTRUCCIONES_FRONTEND_SAAS_MASTER.md` (400 lineas) — SaaS Master Panel.
- `docs/PERMISSIONS_HIERARCHY_DESIGN_2026-07-13.md` (250 lineas) — Diseno Fase 1+2.
- `docs/SCOPES_DESIGN_2026-07-13.md` (400 lineas) — Diseno Fase 3.

## Regenerar la coleccion

Si el backend agrega nuevos endpoints, regenera la coleccion:

```powershell
cd C:\Users\gafit\Documents\INVENTARIOARENS
php scripts\generate-postman.php
php scripts\generate-postman-env.php
```

Los archivos en `C:\Users\gafit\Desktop\INVENTARIOARENS-Postman\` se sobreescriben.

## Troubleshooting

**401 Unauthorized**: Token expirado o invalido. Re-login.

**403 Forbidden**: El user no tiene el permiso requerido. Verifica que el role tiene el permiso (`/api/access/users/{user}/permissions`).

**404 Not Found**: Recurso no existe o no pertenece al tenant. Verifica `{{tenant_id}}` o `{{user_id}}`.

**422 Unprocessable Content**: Validacion fallida. Lee el body del response para ver que campo fallo.

**500 Internal Server Error**: Bug en el backend. Reporta con el endpoint y body de la request.

## Cobertura

| Capa de seguridad | Endpoints | Requests en coleccion |
|---|---|---|
| **Capa 1: Arbol + Roles** | permission-catalog, roles CRUD, duplicate, preview | 10 |
| **Capa 2: Field masking + Overrides** | overrides, effective-permissions | 4 |
| **Capa 3: Resource-level scope** | scopes (5 endpoints) | 6 |
| **SaaS Master Panel** | groups, admins, reset-password | 13 |
| **Validacion end-to-end** | sales, kardex | 2 |
| **Auth** | login, platform-login, me | 3 |
| **Users (base)** | users CRUD, status, roles | 5 |
| **TOTAL** | | **43 requests** |

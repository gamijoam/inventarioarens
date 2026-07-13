# INSTRUCCIONES PARA EL FRONTEND WPF — SaaS Master Panel

> **Para:** El agente opencode que mantiene `desktop/InventoryDesktop/`.
> **Fecha:** 2026-07-13
> **Backend status:** Operativo y desplegado en `https://app.miinventariofacil.com`.
> **Suite backend:** 596 tests, 594 passed, 2 skipped, 0 failures.

---

## 1. Contexto rápido

El backend implementó el **SaaS Master Panel** completo. Los endpoints están todos desplegados y verificados con curl real desde este shell.

El WPF en el commit `d3f7f350` ya implementó:
- ✅ Login como Platform Admin (`POST /api/auth/platform-login`)
- ✅ Panel SaaS Master con tabs (grupos, spinoffs, admins)
- ✅ Persistencia de sesión en `TokenVault`
- ✅ Switch tenant dialog
- ✅ Diálogos para crear grupo/spinoff/platform admin

**Lo que el backend agregó después** (commits `c5670995` + `5309752a`) y el WPF aún no consume:
- 6 endpoints nuevos en `/api/master/*` que verás abajo.
- `/api/auth/me` ahora funciona sin tenant para Platform Admin.
- `PlatformAdminResource` ahora expone `auth_tokens_count` + `last_login_at`.

Este documento te dice **exactamente qué llamar, con qué shape, y qué esperar**.

---

## 2. Convenciones generales

### Headers

TODOS los requests autenticados requieren:
```
Authorization: Bearer <token>
```

NO envíes `X-Tenant` cuando operas como Platform Admin (tu token no tiene tenant).

Los endpoints de login:
- `POST /api/auth/login` → **requiere** `X-Tenant: <slug>`.
- `POST /api/auth/platform-login` → **NO** envíes `X-Tenant`.

### Base URL

- Producción: `https://app.miinventariofacil.com/api/`
- Todos los ejemplos abajo asumen este prefijo.

### Errores

Todos los errores siguen el formato estándar de Laravel:
```json
{
  "message": "Mensaje legible en español.",
  "errors": {
    "campo": ["mensaje específico 1", "mensaje específico 2"]
  }
}
```

Códigos HTTP usados:
- `200` OK
- `201` Created
- `204` No Content (delete exitoso)
- `401` No autenticado / token inválido
- `403` No autorizado (sin permiso o no es Platform Admin)
- `404` Recurso no encontrado
- `422` Validación fallida

---

## 3. Endpoints SaaS Master (todos requieren Platform Admin)

### 3.1 `POST /api/auth/platform-login`

Login como Platform Admin (SaaS Master).

**Request:**
```json
{ "email": "tu@correo.test", "password": "...", "device_name": "wpf-master" }
```

**Response 201:**
```json
{
  "data": {
    "user": {
      "id": 7,
      "name": "Programador Test",
      "email": "tu@correo.test",
      "is_platform_admin": true
    },
    "tenant": null,
    "roles": [],
    "permissions": [],
    "token": "abc123...",
    "token_type": "Bearer",
    "expires_at": "2026-08-12T..."
  }
}
```

**Errores:**
- `422` con `message: "Este usuario no es Platform Admin."` si el email existe pero `is_platform_admin=false`.
- `401` si credenciales inválidas.

**Para el WPF:**
- `tenant` es `null` para Platform Admin. NO intentes hacer `X-Tenant` con este token.
- El token no tiene expiración corta; el frontend debe hacer logout cuando el usuario cierra el panel.

---

### 3.2 `GET /api/auth/me`

Información del usuario actual. **Funciona tanto para tenant user como para Platform Admin.**

**Response 200 (Platform Admin):**
```json
{
  "data": {
    "user": { "id": 7, "name": "...", "email": "...", "is_platform_admin": true },
    "tenant": null,
    "roles": [],
    "permissions": []
  }
}
```

**Response 200 (Tenant user):**
```json
{
  "data": {
    "user": { "id": 5, "name": "...", "email": "...", "is_platform_admin": false },
    "tenant": { "id": 1, "name": "Demo Caracas", "slug": "demo-caracas", "domain": null },
    "roles": ["Owner"],
    "permissions": ["tenants.view", "tenants.create", ...]
  }
}
```

**Para el WPF:**
- Si `is_platform_admin: true` → mostrar el panel SaaS Master.
- Si `tenant` no es null → mostrar el flujo normal de tenant (cajero, admin, etc).
- Si ambos: priorizar Platform Admin.

---

### 3.3 `GET /api/master/stats`

Stats globales del SaaS Master. **Úsalo para el dashboard del panel.**

**Response 200:**
```json
{
  "data": {
    "totals": {
      "platform_admins": 1,
      "total_tenants": 7,
      "total_groups": 7,
      "total_spinoffs": 0,
      "active_tenants": 7,
      "inactive_tenants": 0
    },
    "groups_by_plan": { "demo": 5, "enterprise": 1, "smoke": 1 }
  }
}
```

**Para el WPF:**
- Mostrar `totals` como tarjetas en el dashboard.
- `groups_by_plan` puede ir a un chart de torta o tabla.

---

### 3.4 `GET /api/master/groups`

Lista todos los grupos (tenants con `parent_id = NULL`).

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Demo Caracas",
      "slug": "demo-caracas",
      "domain": null,
      "status": "active",
      "plan": "demo",
      "parent_id": null,
      "is_group": true,
      "spinoffs_count": 0,
      "users_count": 3,
      "created_at": "2026-07-12T...",
      "updated_at": "2026-07-12T..."
    }
  ],
  "meta": { "total": 1, "per_page": 25, "current_page": 1 }
}
```

**Paginación:** 25 por página. Usa `?page=2` para más.

---

### 3.5 `POST /api/master/groups`

Crea un grupo (tenant raíz) + setup inicial (branch + warehouse + BCV opcional) + group_owner.

**Request:**
```json
{
  "name": "Arens Holding",
  "slug": "arens-holding",
  "domain": null,
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

`branch`, `warehouse`, `exchange_rate_type` son opcionales. Si pasas `warehouse` sin `branch`, se ignora.

**Response 201:**
```json
{
  "data": {
    "id": 16,
    "name": "Arens Holding",
    "slug": "arens-holding",
    "is_group": true,
    "spinoffs_count": 0,
    "users_count": 1,
    ...
  }
}
```

**Errores:**
- `422 Ya existe una empresa con ese slug.` — slug duplicado.
- `422 El slug solo puede contener letras minusculas, numeros y guiones.`

---

### 3.6 `GET /api/master/groups/{group}`

Detalle de un grupo. `{group}` puede ser ID o slug.

**Response 200:** mismo shape que el item de `listGroups`.

**Errores:**
- `404` si el tenant no existe.
- `404` si el tenant existe pero no es grupo (es spinoff).

---

### 3.7 `PATCH /api/master/groups/{group}`

Edita nombre, slug, domain, plan o status de un grupo.

**Request (todos los campos opcionales):**
```json
{
  "name": "Arens Holding (Renombrada)",
  "slug": "arens-holding-v2",
  "domain": null,
  "status": "active",
  "plan": "enterprise"
}
```

**Response 200:** grupo actualizado.

**Errores:**
- `422` si el slug ya está en uso por OTRO grupo.
- `404` si no existe o no es grupo.

---

### 3.8 `DELETE /api/master/groups/{group}`

Soft delete: marca `status = inactive`. NO borra la fila.

**Response 204** (sin contenido).

**Idempotente.** Si ya estaba inactive, retorna 204 igual.

**Errores:**
- `404` si no existe o no es grupo.

**Para el WPF:** después de desactivar, quita el grupo de la lista principal o ponlo en una sección "Inactivos". NO borres el grupo del state local hasta recibir 204.

---

### 3.9 `GET /api/master/groups/{group}/tenants`

Lista los spinoffs (empresas hijas) de un grupo.

**Response 200:**
```json
{
  "data": [
    {
      "id": 17,
      "name": "Arens Valencia",
      "slug": "arens-valencia",
      "status": "active",
      "plan": "premium",
      "parent_id": 16,
      "is_group": false,
      "users_count": 1
    }
  ],
  "meta": { "total": 1, "per_page": 25, "current_page": 1 }
}
```

**Errores:**
- `404` si `{group}` no es un grupo.

---

### 3.10 `POST /api/groups/{group}/tenants`

Crea un spinoff dentro de un grupo. **Este endpoint requiere ser Group Owner**, no Platform Admin.

**⚠️ Limitación importante:** desde el panel SaaS Master, este endpoint NO funciona porque el Platform Admin no es Group Owner. Si quieres que el SaaS Master también pueda crear spinoffs, hay que extender el backend (pidelo al backend-dev).

**Request:**
```json
{
  "name": "Arens Valencia",
  "slug": "arens-valencia",
  "admin": {
    "name": "Admin Valencia",
    "email": "admin.valencia@arens.test",
    "password": "Secret123"
  },
  "branch": { "name": "Valencia", "code": "VAL" },
  "warehouse": { "name": "Almacen Valencia", "code": "VAL-01" }
}
```

**Response 201:** spinoff creado.

**Errores:**
- `403` si el user no es Group Owner.
- `404` si `{group}` no es grupo.
- `422` si el slug está duplicado.

**Alternativa para el WPF:** el SaaS Master puede crear el spinoff **directamente** vía `POST /api/tenants` con `parent_id` en el payload (si el backend lo soporta — pedirlo al backend-dev). Por ahora el workaround es:
1. El SaaS Master crea el grupo.
2. El SaaS Master promotes al group_owner vía el command artisan (no vía API).
3. El group_owner hace login y crea los spinoffs desde su panel.

---

### 3.11 `GET /api/master/admins`

Lista todos los Platform Admins.

**Response 200:**
```json
{
  "data": [
    {
      "id": 7,
      "name": "Programador Test",
      "email": "tu@correo.test",
      "is_platform_admin": true,
      "is_active": true,
      "auth_tokens_count": 5,
      "last_login_at": "2026-07-13T...",
      "created_at": "2026-07-12T...",
      "updated_at": "2026-07-13T..."
    }
  ]
}
```

**Para el WPF:** `auth_tokens_count` te dice cuántas sesiones activas tiene (útil para mostrar "5 dispositivos conectados").

---

### 3.12 `POST /api/master/admins`

Crea un Platform Admin nuevo. Si el email ya existe, lo promotes a Platform Admin (no crea duplicado).

**Request:**
```json
{ "name": "Otro Admin", "email": "otro@platform.test", "password": "Secret123" }
```

`password` es opcional. Si no viene, se genera uno aleatorio de 32 chars y se retorna en `initial_password`.

**Response 201 (admin nuevo):**
```json
{
  "data": {
    "id": 11,
    "name": "Otro Admin",
    "email": "otro@platform.test",
    "is_platform_admin": true,
    "auth_tokens_count": 0,
    "last_login_at": null,
    "initial_password": "kZ8sR3pQ..."   // SOLO en creación nueva
  }
}
```

**Response 200 (admin existente promovido):**
```json
{
  "data": { "id": ..., "is_platform_admin": true, "initial_password": null }
}
```

**⚠️ Importante:** `initial_password` SOLO aparece en la respuesta 201. Si el admin ya existía y solo fue promovido, NO se retorna. Es responsabilidad del WPF pedirle al admin que use "forgot password" si era un user nuevo.

---

### 3.13 `GET /api/master/admins/{admin}`

Detalle de un Platform Admin.

**Response 200:** mismo shape que el item de `listAdmins`.

**Errores:**
- `404` si el user no existe o no es Platform Admin.

---

### 3.14 `PATCH /api/master/admins/{admin}`

Edita nombre, email o `is_platform_admin` de un Platform Admin.

**Request:**
```json
{ "name": "Nuevo Nombre", "email": "nuevo@platform.test", "is_platform_admin": true }
```

Todos los campos son opcionales. `is_platform_admin: false` permite **demote** a un admin (lo convierte en user normal, no Platform Admin).

**Response 200:** admin actualizado.

**Errores:**
- `422` con `email` único si el email ya está en uso por OTRO user.
- `404` si no existe o no es Platform Admin.

---

### 3.15 `POST /api/master/admins/{admin}/reset-password`

Resetea la contraseña del admin y **revoca TODAS sus sesiones activas**.

**Request:**
```json
{ "password": "NuevoPassword123" }
```

`password` es opcional. Si no viene, se genera uno aleatorio de 32 chars y se retorna en `initial_password`.

**Response 200:**
```json
{
  "data": {
    "user_id": 11,
    "email": "otro@platform.test",
    "initial_password": "kZ8sR3pQ...",   // null si pasaron password explícito
    "sessions_revoked": true
  }
}
```

**⚠️ Esto invalida TODOS los tokens activos del admin**, incluyendo el que está usando el WPF. Úsalo con cuidado.

---

### 3.16 `DELETE /api/master/admins/{admin}`

Revoca el acceso de Platform Admin: `is_platform_admin = false` + revoca todas las sesiones.

**Response 204** (sin contenido).

**⚠️ NO se puede revocar a sí mismo** (intentarlo retorna 422).

**Errores:**
- `422 No puedes revocar tu propio acceso de Platform Admin.` si intentas borrarte a ti mismo.
- `404` si el user no existe o no es Platform Admin.

---

### 3.17 `POST /api/auth/logout`

Revoca el token actual. Funciona tanto para Platform Admin como para tenant user.

**Response 200:**
```json
{ "data": { "revoked": true } }
```

**Para el WPF:** después del logout, limpia el `TokenVault` y vuelve a la pantalla de login.

---

## 4. Flujos completos del panel SaaS Master

### 4.1 Login + ver stats + logout
```
1. POST /api/auth/platform-login  →  guardar token en TokenVault
2. GET  /api/auth/me               →  mostrar nombre/email del admin
3. GET  /api/master/stats          →  mostrar cards del dashboard
4. ... el usuario interactúa con el panel ...
5. POST /api/auth/logout           →  limpiar TokenVault, volver a login
```

### 4.2 Crear grupo + ver detalle
```
1. POST /api/master/groups          →  crear grupo + group_owner
2. GET  /api/master/groups/{id}     →  mostrar detalle (counts, status, plan)
3. Mostrar `initial_password` del group_owner para que el admin se lo envíe por email
```

### 4.3 Crear spinoff desde grupo
```
1. GET  /api/master/groups           →  elegir grupo
2. POST /api/groups/{slug}/tenants  →  crear spinoff
   (⚠️ Este endpoint requiere ser Group Owner; ver §3.10 para workarounds)
```

### 4.4 Gestión de Platform Admins
```
1. GET  /api/master/admins          →  listar
2. POST /api/master/admins          →  crear
3. PATCH /api/master/admins/{id}    →  editar nombre/email
4. POST /api/master/admins/{id}/reset-password  →  reset
5. DELETE /api/master/admins/{id}   →  revocar (NO self)
```

---

## 5. Permisos necesarios (referencia)

El WPF SaaS Master requiere que el user logueado sea Platform Admin (`is_platform_admin = true`). No usa los permisos de Spatie porque opera FUERA de cualquier tenant.

Si querés crear un Platform Admin nuevo desde el WPF, ya está cubierto por `POST /api/master/admins`. Si querés desde SSH, usar:
```bash
php artisan access:create-platform-admin "Nombre" email@platform.test --password=Secret123
```

---

## 6. UX pendiente de decisión (las 5 preguntas del doc original)

El doc `docs/SAAS_MASTER_LOGIN_DESIGN_2026-07-12.md` (commit d3f7f350) tiene 5 preguntas abiertas (Q1-Q5) sobre UX. **Mis respuestas ya las di en el chat de esa sesión**; las repito aquí para que las tengas a mano:

| # | Pregunta | Respuesta | Acción para el WPF |
|---|---|---|---|
| Q1 | ¿Platform Admin offline? | NO. Siempre online. | No implementar cache local de credenciales. |
| Q2 | ¿Modo programador en ventana aparte o embebido? | **Ventana aparte (Opción B).** | Atajo `Ctrl+Shift+P` o flag en `inventorydesktop.config` para revelar la opción. |
| Q3 | ¿URL en código, config o installer? | **`inventorydesktop.config`** | El `InventorySyncInstaller` lo escribe. El WPF solo lee. |
| Q4 | ¿Sync de platform admin mirror? | NO. | No implementar. |
| Q5 | ¿SaaS Master accesible desde cualquier instalación? | **Solo "edición programador"** (build específica). | `InventorySyncInstaller` solo setea `allowProgrammerMode: true` en builds dev/QA. |

Si querés implementar Q2/Q3 cortoplazo (esta sesión), podés:
1. Revertir los cambios en `LoginView.xaml(.cs)` que hacen visible el campo "Servidor de la API" para todos.
2. Reemplazar por una sección "Modo programador" oculta detrás de `Ctrl+Shift+P` que abre `ProgrammerLoginWindow`.

---

## 7. Datos útiles

### Endpoints que el WPF SaaS Master YA consume y NO debe cambiar
- `POST /api/auth/platform-login` (login)
- `POST /api/master/groups` (crear grupo)
- `GET /api/master/groups` (listar)
- `POST /api/master/admins` (crear admin)
- `GET /api/master/admins` (listar admins)

### Endpoints NUEVOS que el WPF debe empezar a consumir
- `GET /api/auth/me` (info del admin actual — sustituye el parsing manual del `is_platform_admin`)
- `GET /api/master/stats` (dashboard — sustituye el cálculo manual de counts)
- `GET /api/master/groups/{id}` (detalle de grupo)
- `PATCH /api/master/groups/{id}` (editar grupo)
- `DELETE /api/master/groups/{id}` (soft delete)
- `GET /api/master/groups/{id}/tenants` (listar spinoffs desde master)
- `GET /api/master/admins/{id}` (detalle de admin)
- `PATCH /api/master/admins/{id}` (editar admin)
- `POST /api/master/admins/{id}/reset-password` (reset password)
- `DELETE /api/master/admins/{id}` (revocar — con guard de no self)
- `POST /api/auth/logout` (logout platform)

---

## 8. Comandos Artisan utiles (para SSH, no API)

```bash
# Crear o promover un Platform Admin
php artisan access:create-platform-admin "Nombre Admin" email@platform.test --password=Secret123

# Promover un user existente a Platform Admin
php artisan access:promote-admin email@platform.test
```

---

## 9. Para el equipo

1. **No** tocar el endpoint `POST /api/auth/platform-login`. Es estable.
2. **Sí** empezar a consumir los 11 endpoints nuevos documentados arriba.
3. **Sí** implementar `GET /api/auth/me` en lugar de parsear el token manualmente.
4. **Sí** usar `auth_tokens_count` + `last_login_at` para mostrar la lista de admins con su actividad.
5. **Sí** manejar el caso `initial_password` solo en el response 201 de `POST /api/master/admins` y `POST /api/master/admins/{id}/reset-password`. En cualquier otro response es `null`.

Si hay dudas o se necesita extender el backend, abrir un issue y avisar al backend-dev.

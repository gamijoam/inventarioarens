# Bootstrap API — Primer deploy del SaaS

> Endpoint pensado para hacer el primer arranque del sistema desde una base de datos completamente
> vacia, **directamente desde Postman** (sin necesidad de SSH al servidor para correr seeders).

Diseñado para que un solo request HTTP deje funcionando:

1. El primer **Platform Admin** (`is_platform_admin = true`) que controla todos los grupos/spinoffs.
2. (Opcional) Un **tenant inicial** con el admin ya asignado como `Administrador` con los 101 permisos.

Despues de un bootstrap exitoso, todos los endpoints de `/api/master/*` quedan disponibles para crear
mas grupos, empresas y Platform Admins, y `/api/access/users` para crear usuarios dentro de cada empresa.

---

## 1. Configuracion necesaria en el backend

Antes de poder usar el endpoint, el archivo `.env` del backend Laravel debe tener definida la
variable `APP_BOOTSTRAP_TOKEN`. **Si no esta definida, el endpoint rechaza todos los requests con 422.**

```env
# .env (backend Laravel)
APP_BOOTSTRAP_TOKEN=un-token-seguro-de-al-menos-32-caracteres
```

### Como generar un token seguro

```bash
# Linux / Mac
openssl rand -hex 32

# PowerShell
-join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })
```

### Como desactivarlo

Para apagar el endpoint despues del bootstrap inicial:

```env
# Comentar o vaciar la variable
# APP_BOOTSTRAP_TOKEN=
APP_BOOTSTRAP_TOKEN=
```

Con la variable vacia, todos los requests al endpoint devuelven 422 indicando que esta deshabilitado.

---

## 2. Endpoint

### `POST /api/bootstrap`

- **Sin auth** (es publico por diseno, solo se puede usar una vez).
- **Sin X-Tenant** (opera fuera de cualquier empresa).
- **Throttle**: 3 req/hora por IP (`throttle:bootstrap`).
- **Solo funciona si la BD esta completamente vacia** (sin users ni tenants).

### Request body

```json
{
  "name": "SaaS Master",
  "email": "admin@miempresa.test",
  "password": "MiClaveInicial2026!",
  "bootstrap_token": "un-token-seguro-de-al-menos-32-caracteres",

  "tenant": {
    "name": "Mi Empresa Principal",
    "slug": "mi-empresa",
    "domain": "miempresa.miinventariofacil.com",
    "plan": "standard"
  }
}
```

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `name` | string 2-120 | Si | Nombre del Platform Admin |
| `email` | email max 190 | Si | Correo unico (lowercase) |
| `password` | string 8-72 | No | Si se omite, el backend genera uno aleatorio de 32 chars y lo devuelve en `data.initial_password` |
| `bootstrap_token` | string max 200 | Si | Debe coincidir con `APP_BOOTSTRAP_TOKEN` del .env. Tambien puede enviarse en el header `X-Bootstrap-Token` |
| `tenant` | object | No | Si se incluye, crea tambien la primera empresa |
| `tenant.name` | string max 150 | Si con `tenant` | Nombre legible de la empresa |
| `tenant.slug` | slug max 100 | Si con `tenant` | Solo minusculas, numeros y guiones (regex `^[a-z0-9-]+$`) |
| `tenant.domain` | string max 150 | No | Dominio opcional para resolucion por dominio |
| `tenant.plan` | string max 50 | No | Default: `standard` |

### Response 201 — exito

```json
{
  "data": {
    "user": {
      "id": 1,
      "name": "SaaS Master",
      "email": "admin@miempresa.test",
      "is_platform_admin": true
    },
    "tenant": {
      "id": 1,
      "name": "Mi Empresa Principal",
      "slug": "mi-empresa",
      "domain": "miempresa.miinventariofacil.com",
      "plan": "standard",
      "status": "active"
    },
    "token": "abc123...xyz80chars...",
    "token_type": "Bearer",
    "expires_at": "2026-08-13T12:00:00+00:00",
    "initial_password": null,
    "next_steps": {
      "platform_login": "POST /api/auth/platform-login con email + password",
      "create_groups": "POST /api/master/groups para crear un grupo (tenant padre)",
      "create_spinoffs": "POST /api/master/groups/{group}/tenants para empresas hijas",
      "create_tenant_users": "POST /api/access/users dentro de una empresa para usuarios locales"
    }
  }
}
```

- `data.token` es el Bearer token de plataforma (expira en 30 dias). **Guardalo, no se vuelve a mostrar.**
- `data.initial_password` solo aparece si NO enviaste `password` en el request.
- `data.tenant` es `null` si no mandaste el objeto `tenant`.

### Errores

| Codigo | Cuando | Body |
|---|---|---|
| **422** | Falta o es invalido `bootstrap_token` | `{ "errors": { "bootstrap_token": [...] } }` |
| **422** | `APP_BOOTSTRAP_TOKEN` no esta definido en .env | `{ "errors": { "bootstrap": [...] } }` |
| **422** | La BD ya tiene usuarios o tenants | `{ "errors": { "bootstrap": [...] } }` |
| **422** | Body invalido (email mal formado, slug invalido, etc.) | `{ "errors": { "<campo>": [...] } }` |
| **429** | Mas de 3 requests por hora desde la misma IP | `{ "message": "Demasiados intentos..." }` |

---

## 3. Lo que hace internamente

`app/Modules/Bootstrap/Services/BootstrapService.php` ejecuta, en una sola transaccion de DB:

1. Verifica que `APP_BOOTSTRAP_TOKEN` este seteado y coincida con el enviado.
2. Verifica que la tabla `users` Y `tenants` esten vacias.
3. Crea el `User` con `is_platform_admin=true`, `email_verified_at=now()`.
4. (Si mandaste `tenant`):
   - Crea el `Tenant` con `status=active`, `plan=standard` (o el que pasaste).
   - Asocia el admin al tenant en la pivot `tenant_user` con `status=active`.
   - Sembra los **101 permisos base** en la tabla `permissions` (`Permission::findOrCreate`).
   - Sembra los **6 roles base** (`Owner`, `Administrador`, `Gerente`, `Vendedor`, `Almacen`, `Auditor`) con sus permisos en el `team_id = tenant.id`.
   - Asigna el rol `Administrador` al admin dentro de ese tenant (los 101 permisos efectivos).
5. Emite un `AuthToken` con `tenant_id = null` (token global de plataforma), `abilities = ['platform']`, expira en 30 dias.
6. Audita `bootstrap.completed` en `audit_logs` con IP, UA y datos del usuario creado.

Si **NO** mandaste `tenant`, NO se siembran permisos ni roles (porque no hay `team_id` al cual asociarlos). Esto es intencional: cuando despues crees el primer grupo/tenant con `/api/master/groups`, se sembraran automaticamente para ese team.

---

## 4. Flujo recomendado para el primer deploy

### Paso 1: Configurar el .env del backend

```env
APP_BOOTSTRAP_TOKEN=$(openssl rand -hex 32)
```

Reiniciar PHP-FPM / `php artisan config:clear` para que tome la nueva variable.

### Paso 2: Configurar la variable en Postman

En el environment `INVENTARIOARENS — Production` editar la variable `bootstrap_token` con el mismo valor.

### Paso 3: Primer request (sin tenant)

```bash
curl -X POST https://app.miinventariofacil.com/api/bootstrap \
  -H "Content-Type: application/json" \
  -d '{
    "name": "SaaS Master",
    "email": "admin@miempresa.test",
    "password": "MiClaveInicial2026!",
    "bootstrap_token": "<tu-token>"
  }'
```

Response 201 → copiar `data.token` a `{{auth_token}}` en Postman.

### Paso 4: Crear el primer grupo y empresa desde SaaS Master

```
POST /api/master/groups
Authorization: Bearer {{auth_token}}
Content-Type: application/json

{
  "name": "Mi Grupo Empresarial",
  "slug": "mi-grupo",
  "plan": "standard"
}
```

```
POST /api/master/groups/{group}/tenants
Authorization: Bearer {{auth_token}}
Content-Type: application/json

{
  "name": "Mi Empresa Principal",
  "slug": "mi-empresa",
  "plan": "standard"
}
```

### Paso 5: Crear el primer usuario Administrador del tenant

Login con la cuenta de Platform Admin → usar `/api/master/groups/{group}/tenants/{tenant}/users` para
asociar el admin como miembro del grupo, o entrar al tenant creado y usar `/api/access/users` para
crear usuarios locales con sus roles.

### Paso 6: Desactivar el bootstrap

Despues del primer deploy exitoso, **vaciar la variable en .env** para que nadie mas pueda usar el
endpoint:

```env
APP_BOOTSTRAP_TOKEN=
```

---

## 5. Como crear empresas adicionales despues del bootstrap

Una vez que el primer Platform Admin esta creado y la BD no esta vacia, el endpoint `/api/bootstrap`
siempre devuelve 422. Para agregar mas empresas se usa el flujo SaaS Master:

1. **`POST /api/master/groups`** — crea un grupo (tenant padre con `parent_id=null`).
2. **`POST /api/master/groups/{group}/tenants`** — crea un spinoff (empresa hija con `parent_id=group.id`).
3. **Login al spinoff** con `POST /api/auth/login` (header `X-Tenant: <slug>`).
4. **`POST /api/access/users`** — crea usuarios dentro de la empresa con rol `Administrador`.

Alternativa: si queres promover un usuario existente a Platform Admin:

- **`POST /api/master/admins`** — crea/promueve un Platform Admin (requiere ser Platform Admin para
  invocarlo). El controller es `PlatformAdminController::store` en `app/Modules/Tenancy/`.

---

## 6. Tests

Cubiertos en `tests/Feature/Bootstrap/BootstrapApiTest.php` (17 tests, 81 aserciones):

- `test_creates_platform_admin_when_database_is_empty` — caso feliz basico.
- `test_returned_token_works_for_master_endpoints` — el token funciona contra `/api/master/admins`.
- `test_returned_token_works_for_platform_login_round_trip` — el token funciona contra `/api/auth/me`.
- `test_returns_generated_password_when_not_provided` — password aleatoria.
- `test_omits_initial_password_when_provided` — password explicita.
- `test_creates_initial_tenant_and_assigns_administrador_role` — caso con tenant + 101 permisos.
- `test_rejects_when_database_is_not_empty` — 422 si hay users.
- `test_rejects_when_tenant_already_exists` — 422 si hay tenants.
- `test_rejects_when_token_is_wrong` — 422 con token incorrecto.
- `test_rejects_when_token_is_missing` — 422 sin token.
- `test_accepts_token_via_header` — token via `X-Bootstrap-Token` header.
- `test_rejects_invalid_payload` — 422 con email/nombre invalidos.
- `test_rejects_invalid_tenant_slug` — 422 con slug con espacios.
- `test_rejects_when_bootstrap_disabled` — 422 si `APP_BOOTSTRAP_TOKEN` vacio.
- `test_throttle_limits_requests_per_ip` — 429 despues de 3 req/hora.
- `test_writes_audit_log_for_bootstrap_completed` — audit OK.
- `test_writes_audit_log_for_bootstrap_rejected` — audit de rechazo.

Correr localmente:

```bash
php vendor/bin/phpunit tests/Feature/Bootstrap/
```

---

## 7. Archivos creados / modificados

```
app/Modules/Bootstrap/
├── Controllers/BootstrapController.php      ← endpoint POST /api/bootstrap
├── Requests/BootstrapRequest.php            ← validación de body
├── Resources/BootstrapResource.php          ← shape del response
├── Services/BootstrapService.php            ← lógica de negocio + audit
└── routes.php                               ← registro de ruta con throttle:bootstrap

routes/api.php                               ← require del modulo
app/Providers/AppServiceProvider.php         ← throttle 'bootstrap' (3/hora)
phpunit.xml                                  ← APP_BOOTSTRAP_TOKEN para testing
tests/Feature/Bootstrap/BootstrapApiTest.php ← 17 tests
docs/BOOTSTRAP_API.md                        ← este archivo
```

---

## 8. Consideraciones de seguridad

- **Defensa en profundidad**: el endpoint exige las 3 cosas (BD vacia + token + throttle). Si una
  falla, las demas no se evalúan y se registra un audit `bootstrap.rejected`.
- **Rate limiting**: 3 req/hora por IP (`Limit::perHour(3)`).
- **Audit**: cualquier intento (exitoso o rechazado) queda en `audit_logs` con IP y UA.
- **Token compare**: usa `hash_equals()` para evitar timing attacks.
- **Password aleatoria**: si no se especifica, se genera con `Str::random(32)` y se devuelve **una sola vez** en el response.
- **Token plano**: se devuelve una sola vez en el response (igual que el resto de los endpoints de login).
- **Hash**: el token se guarda en DB hasheado con `sha256` (no se puede re-emitir).
- **Desactivacion**: el admin puede apagar el endpoint en cualquier momento comentando/vaciando la variable de entorno.
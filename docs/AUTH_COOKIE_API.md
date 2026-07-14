# AUTH_COOKIE_API

> Contrato de autenticacion para el frontend SPA.
> Version: 2026-07-14 · Plan C (hibrido Bearer + Cookie httpOnly)

## Resumen ejecutivo

El backend acepta el token de sesion en DOS formatos:

| Formato | Quien lo usa | Como viaja |
|---|---|---|
| `Authorization: Bearer <token>` (header) | Sync worker, Postman, scripts PHP, integraciones externas | Manual (no automatico) |
| Cookie `auth_token=<token>` (httpOnly) | **Frontend SPA (navegador)** | Automatico via browser |

Ambos formatos funcionan **simultaneamente** sin cambios en el sync worker ni en Postman.

Cuando el request trae **Bearer**, se considera un cliente API clasico (sin cookies, sin CSRF).
Cuando el request **NO trae Bearer**, se considera el frontend SPA y el backend exige CSRF protection.

---

## Por que migrar a cookies

| Antes (Bearer en localStorage) | Ahora (cookie httpOnly) |
|---|---|
| Token expuesto a JS | Token NO accesible desde JS |
| XSS roba token | XSS no puede leer cookie httpOnly |
| Token persiste en filesystem | Cookie vive en cookie jar del navegador |
| Frontend debe sincronizar state async post-refresh | Browser envia cookie auto, frontend puede leer estado sync via `document.cookie` |

El token en Bearer sigue siendo util para integraciones server-to-server, scripts CLI y herramientas de debug.

---

## Contrato del backend

### Endpoints de auth

Todos los endpoints viven bajo `/api/auth/*` y mantienen compatibilidad con el contrato anterior.

| Endpoint | Cambio |
|---|---|
| `POST /api/auth/login` | **AHORA** emite cookie `auth_token` httpOnly si el request parece SPA |
| `POST /api/auth/platform-login` | **AHORA** emite cookie igual que login normal |
| `GET /api/auth/me` | Lee Bearer O cookie. Identico shape de response. |
| `POST /api/auth/logout` | Revoca token en DB. **AHORA** limpia cookie si estaba autenticado via cookie. |
| `POST /api/auth/logout-all` | Revoca todos los tokens del tenant. **AHORA** limpia cookie si estaba via cookie. |
| `POST /api/auth/switch-tenant` | Rota cookie si user estaba via cookie. Emite nuevo token en body. |
| `GET /api/auth/sessions` | Sin cambios (siempre requiere Bearer o cookie). |
| `DELETE /api/auth/sessions/{tokenId}` | Sin cambios. |

### Cookie `auth_token`

| Atributo | Valor | Por que |
|---|---|---|
| `Name` | `auth_token` | Constante. Usar `CookieIssuer::COOKIE_NAME` en backend. |
| `Value` | Token plain (80 chars random) | Identico al token retornado en `data.token` del response. |
| `HttpOnly` | `true` | JS no puede leerla (mitigacion XSS). |
| `Secure` | `true` en produccion, `false` en dev | Solo viaja por HTTPS. Forzar con `APP_FORCE_SECURE_COOKIES=true` si usas ngrok/caddy. |
| `SameSite` | `Lax` | No se envia en cross-site POSTs (mitigacion CSRF basica). PERO se envia en navegacion top-level (links compartidos funcionan). |
| `Path` | `/` | Aplica a toda la API. |
| `Expires` | 30 dias desde emision | Alineado con `expires_at` del AuthToken en DB. |
| `Domain` | (auto) | Mismo dominio del backend. |

### CSRF protection (defensa en profundidad)

Requests autenticados via **cookie** deben incluir AMBOS headers:

| Header | Valor | Por que |
|---|---|---|
| `X-Requested-With` | `XMLHttpRequest` | Los formularios HTML nativos no pueden setear este header. |
| `Origin` | Uno de los origins en `app.allowed_origins_for_csrf` (CSV en `.env`: `APP_ALLOWED_ORIGINS_FOR_CSRF`). En dev suele ser `http://localhost:5173` (Vite); en prod, solo el origin publico (ej: `https://app.miinventariofacil.com`). | Defense-in-depth: bloquea requests cross-origin que SI podrian setear X-Requested-With via JS (ej: XSS en otro origin). |

Requests autenticados via **Bearer** NO requieren estos headers (porque el token NO se envia automaticamente, no hay riesgo CSRF).

Si un request llega con cookie `auth_token` pero sin `X-Requested-With` o con `Origin` incorrecto, el backend responde `403 csrf_required`.

### Shape de response (sin cambios)

Login, switch-tenant y platform-login siguen retornando el mismo shape:

```json
{
  "data": {
    "token": "<plain_token>",
    "token_type": "Bearer",
    "expires_at": "2026-08-13T16:53:30.000000Z",
    "user": { "id": 1, "email": "...", "name": "...", "is_active": true },
    "tenant": { "id": 1, "slug": "...", "name": "...", "domain": "..." },
    "roles": ["Administrador"],
    "permissions": ["accounts_payable.pay", "products.view", ...]
  }
}
```

PERO **adicionalmente** el response de login/platform-login/switch-tenant incluye el header `Set-Cookie: auth_token=<value>; ...` cuando se cumplen las condiciones de emision (ver seccion siguiente).

### Cuando el backend emite cookie

El backend emite `Set-Cookie: auth_token=...` solo si el request de login cumple **al menos una** de estas condiciones:

1. Trae header `X-Requested-With: XMLHttpRequest`, O
2. Trae header `Origin` que matchea uno de los origins en `app.allowed_origins_for_csrf` (config desde `APP_ALLOWED_ORIGINS_FOR_CSRF` en `.env`)

Y **NO** trae `Authorization: Bearer` (los clientes API no reciben cookie).

El frontend **siempre** debe enviar `X-Requested-With: XMLHttpRequest` en todas sus requests para garantizar la emision de cookie y sortear el chequeo CSRF.

---

## Guia de integracion para el frontend

### 1. Cliente HTTP (axios)

Configurar axios con `withCredentials: true` para que el navegador envie la cookie automaticamente:

```ts
import axios from 'axios';

const api = axios.create({
  baseURL: '/api',
  timeout: 30_000,
  withCredentials: true,  // ← CRITICO: enviar cookies en cross-origin requests
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',  // ← CRITICO: requerido para CSRF
  },
});
```

**Quitar el interceptor que inyecta `Authorization: Bearer`**. La cookie viaja sola, no se debe manipular.

### 2. Store de sesion

El store **NO debe persistir el token** (la cookie se encarga). Solo persiste el resto:

```ts
// stores/session.ts
export const useSessionStore = create<SessionState>()(
  persist(
    (set, get) => ({
      token: null,  // ← NULL: el token vive en la cookie httpOnly
      user: null,
      tenant: null,
      roles: [],
      permissions: new Set(),
      scopes: emptyScopes,
      scopeStatus: 'none',
      expiresAt: null,

      setSession: (data) =>
        set({
          // token: NO se setea (viene en la cookie httpOnly)
          expiresAt: data.expiresAt,
          user: data.user,
          tenant: data.tenant,
          roles: data.roles,
          permissions: new Set(data.permissions),
          scopeStatus: data.scopeStatus,
          scopes: data.scopes,
        }),

      clearSession: () => set({ ...initialState, permissions: new Set() }),
      // ...
    }),
    {
      name: 'inventory_session',
      partialize: (state) => ({
        // Solo persistir user/tenant/expiresAt/permissions/roles/scopes.
        // El token NO (lo maneja la cookie httpOnly).
        expiresAt: state.expiresAt,
        user: state.user,
        tenant: state.tenant,
        roles: state.roles,
        permissions: Array.from(state.permissions),  // Set -> Array para JSON
        scopeStatus: state.scopeStatus,
        scopes: state.scopes,
      }),
    },
  ),
);
```

### 3. Routing (sync detection de sesion)

Para resolver el bug de "Cargando sesion...", las rutas protegidas deben detectar sesion **sync** leyendo `document.cookie`:

```ts
// routes/_authed.tsx - beforeLoad (lee ANTES de renderizar)
export const Route = createFileRoute('/_authed')({
  beforeLoad: () => {
    // Verificar cookie sincronamente (sin request HTTP).
    const hasCookie = document.cookie.split('; ').some(c => c.startsWith('auth_token='));
    if (!hasCookie) {
      throw redirect({ to: '/login' });
    }
    // Si hay cookie, dejamos pasar. El store hidratado tiene los datos.
  },
  component: () => <RequireAuth><Outlet /></RequireAuth>,
});
```

```ts
// routes/login.tsx - beforeLoad
export const Route = createFileRoute('/login')({
  beforeLoad: () => {
    const hasCookie = document.cookie.split('; ').some(c => c.startsWith('auth_token='));
    if (hasCookie) {
      throw redirect({ to: '/dashboard' });
    }
  },
  component: LoginPage,
});
```

**Esto resuelve el bug**: el frontend ya no necesita hacer `/me` solo para detectar si hay sesion, lee la cookie sync.

### 4. signIn / signOut

```ts
async function signIn(tenantSlug: string, payload: LoginRequest) {
  // No enviar Authorization header. El X-Requested-With se setea por el cliente.
  const { data } = await api.post<LoginResponse>(
    '/auth/login',
    payload,
    { headers: { 'X-Tenant': tenantSlug } }
  );
  // La cookie YA viene en el response (Set-Cookie). El navegador la guarda.
  // Guardamos el resto en el store (sin token).
  useSessionStore.getState().setSession({
    expiresAt: data.expires_at,
    user: data.user,
    tenant: data.tenant,
    roles: data.roles,
    permissions: data.permissions,
    scopeStatus: data.scope_status,
    scopes: data.scopes,
    // NO pasamos token: el store ignora ese campo ahora.
  });
}

async function signOut() {
  try {
    await api.post('/auth/logout');
    // El backend limpia la cookie. No hay que hacer nada local para la cookie.
  } finally {
    useSessionStore.getState().clearSession();
  }
}
```

### 5. Manejo de 401

El interceptor 401 debe limpiar el store y redirigir a `/login`. PERO ahora la cookie la limpia el backend, no necesitamos `document.cookie = ''`.

```ts
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      useSessionStore.getState().clearSession();
      // Redirigir a /login via router (no window.location.href para preservar SPA).
      router.navigate({ to: '/login' });
    }
    return Promise.reject(error);
  },
);
```

### 6. Tenant switcher

```ts
async function switchTenant(slug: string) {
  const { data } = await api.post('/auth/switch-tenant', { tenant_slug: slug });
  // El backend rota la cookie automaticamente (vieja expirada + nueva emitida).
  // Solo actualizamos el store.
  useSessionStore.getState().setSession({
    expiresAt: data.expires_at,
    user: data.user,
    tenant: data.tenant,
    roles: data.roles,
    permissions: data.permissions,
    scopeStatus: data.scope_status,
    scopes: data.scopes,
  });
}
```

### 7. QueryClient y queries

Las queries existentes (useProducts, useMe, etc.) siguen funcionando. El cambio es que:
- El cliente HTTP envia la cookie automaticamente.
- Si la cookie expiro (token revocado, sesion expirada), el backend responde 401, el interceptor limpia el store y redirige.

Si tienes queries que dependen de `useSessionStore.token`, refactoriza para leer del store hidratado (que ahora tiene `user/tenant/permissions` pero no `token`).

### 8. Tests del frontend

Para testear componentes que usan el cliente HTTP, mockear axios con `withCredentials: true` en el setup.

Si testeas `RequireAuth`, el store necesita tener `permissions.size > 0` para que pase sin `/me`.

---

## Lo que NO cambia

- **Bearer sigue funcionando para sync worker y Postman**. No tocar `scripts/sync-worker.cmd` ni `scripts/generate-postman.php`.
- **Shape del response JSON no cambia**. Login/me/switch-tenant retornan los mismos campos.
- **Tests E2E del backend (`tests/Feature/Auth/AuthApiTest.php`) siguen pasando con Bearer**.
- **CSRF solo se exige a requests via cookie**. Bearer esta exento.

## Configuracion del backend (CSRF Origin Allowlist)

El backend valida el header `Origin` contra una lista de origins permitidos.
Esto evita que un XSS en otro dominio pueda hacer requests autenticados via
cookie (que el navegador envia automaticamente).

### Variable de entorno

```
# .env (production)
APP_ALLOWED_ORIGINS_FOR_CSRF=https://app.miinventariofacil.com

# .env (dev local con Vite)
APP_ALLOWED_ORIGINS_FOR_CSRF=http://localhost,http://localhost:5173,http://localhost:8000,http://127.0.0.1:5173,http://127.0.0.1:8000
```

Sin la variable configurada, el backend cae en fallback a `app.url` (un solo
origin), que es estricto pero valido.

### Por que no comparar solo con `app.url`?

En desarrollo, `app.url=http://localhost` (backend en :8000) pero el navegador
abre `http://localhost:5173` (Vite dev server). El proxy de Vite redirige a
backend, pero el `Origin` que envia el navegador es `:5173`. Si solo se
compara contra `app.url`, todos los requests CSRF-protected darian 403.

La allowlist resuelve esto sin comprometer seguridad: cada origin debe ser
explicitado por el operador del SaaS via `.env`.

### Que pasa en produccion?

Solo el origin publico del SaaS deberia estar en `APP_ALLOWED_ORIGINS_FOR_CSRF`.
Cualquier origin adicional que el backend ACEPTABA requests autenticadas via cookie
es un agujero CSRF.

### Tests relevantes

`tests/Feature/Auth/CookieAuthTest.php`:
- `test_cookie_request_with_origin_in_allowlist_is_accepted`: Vite origin pasa.
- `test_cookie_origin_check_normalizes_ports`: puerto incorrecto = 403.
- `test_cookie_request_with_wrong_origin_is_rejected`: origin malicioso = 403.

---

## Tests backend relevantes

- `tests/Feature/Auth/CookieAuthTest.php` (nuevo, 10 tests):
  - Emision de cookie en login
  - No emision cuando hay Bearer
  - Auth via cookie con CSRF
  - CSRF bloquea sin X-Requested-With
  - CSRF bloquea con Origin incorrecto
  - Bearer sigue funcionando sin CSRF
  - Logout limpia cookie
  - Logout via Bearer no limpia cookie
  - Switch-tenant rota cookie
  - Sin token = 401

Para correrlos:
```bash
php vendor/bin/phpunit tests/Feature/Auth/CookieAuthTest.php
```

---

## Debugging

### "Mi cookie no se esta enviando"

Verificar:
1. El cliente HTTP tiene `withCredentials: true`
2. El navegador NO esta en modo incognito con cookies bloqueadas
3. `Set-Cookie` viene en el response del login (F12 > Network > login > Response Headers)
4. La cookie tiene `HttpOnly` Y `SameSite=Lax` Y `Path=/`

### "Recibo 403 csrf_required"

Falta header `X-Requested-With: XMLHttpRequest` o `Origin` no coincide con `app.url` del backend.

Verificar configurando axios con `headers: { 'X-Requested-With': 'XMLHttpRequest' }` en el `create()` o agregandolo en cada request.

### "Mi Bearer dejo de funcionar"

Si envias `Authorization: Bearer` + cookie, el backend prioriza Bearer (no exige CSRF). Bearer deberia seguir funcionando.

Si envias `Authorization: Bearer` + `X-Requested-With: XMLHttpRequest` + sin Origin matching, el backend emite cookie. Eso no deberia romper Bearer (Bearer sigue funcionando, cookie adicional no molesta).

---

## Referencias en el codigo

- `app/Modules/Auth/Services/CookieIssuer.php` - emisor de cookies
- `app/Modules/Auth/Middleware/AuthenticateApiToken.php` - lee Bearer O cookie + CSRF
- `app/Modules/Auth/Controllers/AuthController.php` - emite/rota/limpia cookies
- `bootstrap/app.php` - excluye `auth_token` del cifrado automatico de Laravel
- `tests/Feature/Auth/CookieAuthTest.php` - 10 tests del contrato

## Changelog

- 2026-07-14: Implementacion inicial (Plan C hibrido).
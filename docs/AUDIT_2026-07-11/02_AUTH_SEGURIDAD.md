# Auditoría Auth + Seguridad — 2026-07-11

**Score: 6.5 / 10**
**Estado:** Fundamentos sólidos (token hashing, tenant binding, bcrypt). Gaps significativos en brute-force protection, password reset, security headers, CORS.

---

## 1. Lo confirmado

| # | Hallazgo | Archivo:línea |
|---|---|---|
| 1 | Passwords hashed con bcrypt; cost configurable via `BCRYPT_ROUNDS=12` | `app/Models/User.php:56`, `.env.example:17` |
| 2 | Bcrypt verification constant-time via `Hash::check` | `app/Modules/Auth/Services/AuthService.php:120` |
| 3 | Tokens SHA-256 hashed antes de almacenar (no plaintext en DB) | `AuthService.php:65`, `SyncTokenService.php:27` |
| 4 | 80-char `Str::random` token = ~480 bits entropy | `AuthService.php:60`, `SyncTokenService.php:20` |
| 5 | Token → tenant binding enforced server-side (403 si mismatch) | `ResolveTenant.php:27` |
| 6 | User → tenant binding enforced on login AND switch-tenant | `AuthService.php:54` |
| 7 | Email normalizado a lowercase antes de lookup | `AuthService.php:19, 117` |
| 8 | TTL explícito: 30 días user tokens, 1-1095 sync tokens | `AuthService.php:67`, `IssueSyncTokenRequest.php:18` |
| 9 | Token revocation via `revoked_at` column con `whereNull` filter | `AuthToken.php:37`, `AuthenticateApiToken.php:26` |
| 10 | Logout-all endpoint revoca todos los tokens del (user × tenant) par | `AuthService.php:105-112` |
| 11 | IP y User-Agent captured at token issue time (forensics trail) | `AuthService.php:68-69` |
| 12 | `last_used_at` actualizado en cada authenticated request | `AuthenticateApiToken.php:35` |
| 13 | Modern mass-assignment protection: `#[Fillable(...)]` attribute | `User.php:18`, `AuthToken.php:11-22` |
| 14 | Sensitive attributes hidden en JSON serialization | `User.php:19` |
| 15 | `AuthSessionResource` no leak password/token_hash/remember_token | `AuthSessionResource.php:10-27` |
| 16 | JSON exception rendering gated on `api/*` routes | `bootstrap/app.php:27-30` |
| 17 | Session config defaults to `http_only=true`, `same_site=lax` | `config/session.php:185, 202, 231` |
| 18 | Nginx adds `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff` | `scripts/vps_configure_app_domain.py:14-15` |
| 19 | No `dd()`, `dump()`, `var_dump()`, `print_r()` en código de producción | grep |
| 20 | No `env()` helper calls fuera de `config/` | grep |
| 21 | No password/token/secret plaintext leak en ningún `response()->json()` | grep |
| 22 | Spatie Permission `teams` correctamente wired for tenant isolation | `2026_07_02_174319_create_permission_tables.php:14, 40, 47` |
| 23 | `tenant_id` FK en `auth_tokens` cascades on user/tenant delete | `2026_07_03_120000_create_auth_tokens_table.php:13-14` |

---

## 2. Issues CRÍTICOS

### C1. Sin rate limiting / brute-force protection en login o tenant-lookup
- **Donde:** `app/Modules/Auth/routes.php:8-10`, `bootstrap/app.php:21-25`
- `POST /api/auth/login` solo tiene `tenant` middleware.
- `POST /api/auth/tenants` no tiene middleware.
- **Impacto:** Atacante con email válido puede intentar contraseñas ilimitadas por segundo. Con bcrypt cost 12 (~250ms/attempt), ~4 req/s/single-thread; trivialmente paralelizable.

### C2. Email enumeration via `POST /api/auth/tenants`
- **Donde:** `AuthService.php:16-38`, `AuthController.php:23-30`
- Email inexistente → `{ "data": [] }`. Email existente → `{ "data": [...] }`. Diferente response shape es un oracle.
- Adicionalmente, `User::query()->where('email', ...)->first()` (línea 18) retorna `null` inmediatamente vs corre pivot query para conocido → ~250ms timing difference medible.

### C3. `FRONTEND_DEV_BYPASS_LOGIN` flag enables unauthenticated portal access si misconfigured
- **Donde:** `resources/views/welcome.blade.php:9`, `.env.example:6`
- El check es `app()->environment('local') && (bool) env('FRONTEND_DEV_BYPASS_LOGIN', false)`.
- Si en VPS alguien setea `APP_ENV=local` + `FRONTEND_DEV_BYPASS_LOGIN=true` (inadvertidamente), el portal renderiza botón "Entrar en modo demo local" que bypassa auth.

---

## 3. Issues HIGH

### H1. No password reset / forgot-password flow
- `grep "Password::|ResetPassword|forgot|reset_password"` → 0 hits en `app/`.
- Recovery process undocumented.

### H2. No password complexity o minimum-length policy
- `LoginRequest.php:18` — `'password' => ['required', 'string']` — solo `string`.
- `StoreTenantUserRequest.php:14` — `'min:8'` solamente, no case/digit/symbol requirement.

### H3. Long-lived sync tokens (default 365 días, max 1095 días)
- **Donde:** `SyncTokenService.php:21` (default 365d), `IssueSyncTokenRequest.php:18` (max 1095d).
- Token leaked es válido por hasta 3 años. No rotation cadence.

### H4. `AuthenticateApiToken` middleware bypass cuando session user present
- **Donde:** `AuthenticateApiToken.php:17-19`
- `if (! $plainToken && $request->user()) { return $next($request); }`
- Si un session cookie se setea (web guard leak, future SPA), API auth check es skipped.

### H5. No `device_name` / `user_agent` change detection on token reuse
- **Donde:** `AuthService.php:64-69` captura, pero no compara at validation time.
- Token robado de PC-A puede usarse desde PC-B sin alerting.

### H6. No audit log on login success or failure
- `AuthService::login()` y `validateCredentials()` no llaman `AuditLogger`.

### H7. Sync token issuance requiere solo `belongsToTenant`, no specific permission
- **Donde:** `SyncController.php:96-117`, `IssueSyncTokenRequest.php:9-11`
- Cualquier user del tenant puede mintar sync token de hasta 3 años.

---

## 4. Issues MEDIUM

- **M1:** Email enumeration via response timing en `validateCredentials`
- **M2:** No `WWW-Authenticate` header en 401 responses
- **M3:** No CORS configuration (no `config/cors.php`)
- **M4:** No application-level security headers (CSP, HSTS, Referrer-Policy)
- **M5:** Tenant resolution falls back to `Host` header sin trusted-proxy config
- **M6:** `last_used_at` write en cada request (DB write amplification)
- **M7:** `logout-all` solo revoca tokens en current tenant (no cross-tenant)
- **M8:** No password rotation / expiry
- **M9:** No email-verification gate (User tiene `email_verified_at` pero no se chequea)
- **M10:** `device_name` no validado para length/format
- **M11:** `remember_token` field existe sin feature
- **M12:** `AuthToken::isActive()` definido pero nunca llamado (DRY violation)
- **M13:** `permissions` field `['*']` en cada token (wildcard)
- **M14:** No `Request::ip()` validation against trusted proxies

---

## 5. Attack surface mapping

| # | Endpoint / Surface | Attack Vector | Mitigation actual | Riesgo residual |
|---|---|---|---|---|
| 1 | `POST /api/auth/tenants` | Email enumeration | None | **HIGH** |
| 2 | `POST /api/auth/login` | Credential brute force | Bcrypt cost 12 | **HIGH** (no throttle) |
| 3 | `POST /api/auth/login` | Long-lived token theft | 30-day TTL + revocation | MEDIUM |
| 4 | `POST /api/auth/login` | Stolen token from another tenant | `token.tenant_id` check | LOW |
| 5 | `POST /api/auth/login` | Session-based bypass | Web guard not on API routes | LOW |
| 6 | `POST /api/auth/switch-tenant` | Cross-tenant escalation | `belongsToTenant` check | LOW |
| 7 | `POST /api/auth/logout` | CSRF | Bearer-only | LOW |
| 8 | `POST /api/auth/logout-all` | Mass revocation incomplete | Tenant-scoped | MEDIUM |
| 9 | `POST /api/sync/tokens` | Long-lived token issuance | `belongsToTenant` only | **MEDIUM-HIGH** |
| 10 | Bearer token en transport | MITM | HTTPS via Nginx, no HSTS | MEDIUM |
| 11 | SHA-256 token hash collision | Brute force | 80-char random (~480 bits) | NEGLIGIBLE |
| 12 | `FRONTEND_DEV_BYPASS_LOGIN` | Env misconfiguration | `app()->environment('local')` gate | MEDIUM |
| 13 | `Host` header injection | Tenant misrouting | `token.tenant_id` is authoritative | LOW |
| 14 | `User-Agent` header injection | Log poisoning | `max:120` sin char class | LOW |
| 15 | WPF DPAPI token vault | Local malware extracts Bearer | DPAPI per-Windows-user | MEDIUM |
| 16 | Cloud-side token lifetime | 365d default | None | MEDIUM |
| 17 | Forgotten-password flow | (missing) | N/A | HIGH |

---

## 6. Propuestas

### Effort S (< 1 día)

| # | Propuesta |
|---|---|
| S1 | `throttle:5,1` en `POST /api/auth/login` y `POST /api/auth/tenants` |
| S2 | Named rate limiter `auth` con `5/minute` per IP+email composite |
| S3 | Equalizar timing en `validateCredentials` con `Hash::check` random si user null |
| S4 | `Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->symbols()` |
| S5 | `min:8` mínimo en `LoginRequest::password` |
| S6 | `WWW-Authenticate: Bearer realm="api"` en 401 |
| S7 | `IssueSyncTokenRequest::authorize()` con permission gate |
| S8 | Cap `days` at 365 (no 1095) |
| S9 | Bloquear `FRONTEND_DEV_BYPASS_LOGIN` si `APP_ENV !== 'local'` |
| S10 | Validate `device_name` con regex |
| S11 | Throttle `last_used_at` write (solo si >5 min) |
| S12 | Refactor `AuthToken::isActive()` + use it en middleware |
| S13 | Sanitize `tenant.domain` exposure en `AuthSessionResource` |
| S14 | Default `days` to 90 (not 365) en `IssueSyncTokenService` |

### Effort M (1-3 días)

- M1: Password reset flow completo
- M2: `password_changed_at` migration + force-change-on-first-login
- M3: `SecurityHeadersMiddleware` (CSP, X-Frame, X-Content-Type, Referrer-Policy, Permissions-Policy, COOP, COEP, CORP)
- M4: `config/cors.php` + register `fruitcake/php-cors`
- M5: Trusted proxies middleware
- M6: Audit logging on auth events
- M7: User_agent change detection
- M8: `GET /api/auth/sessions` (list) + `DELETE /api/auth/sessions/{id}` (revoke single)
- M9: HSTS header en Nginx
- M10: Reject tokens con wildly different `user_agent`
- M11: `belongsToTenant` check ANTES de password validation
- M12: Per-permission token scopes

### Effort L (> 3 días)

- L1: TOTP 2FA para Owner/Administrador
- L2: Refresh-token pattern (access 15min + refresh 7 días)
- L3: WebAuthn support
- L4: Anomaly detection engine
- L5: Migrar a `Str::random(64)` + prefix pattern
- L6: Session/device management UI
- L7: OAuth2/OIDC server (Passport)

---

## 7. Headers de seguridad recomendados

```
Strict-Transport-Security: max-age=63072000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY (API) / SAMEORIGIN (web)
Referrer-Policy: strict-origin-when-cross-origin (web) / no-referrer (API)
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Content-Security-Policy: default-src 'self'; ...
```

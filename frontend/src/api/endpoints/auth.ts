/**
 * Endpoints de autenticacion.
 *
 * Con el Plan C hibrido (cookie httpOnly), este cliente YA NO envia
 * `Authorization: Bearer`. El token vive en la cookie httpOnly que el
 * navegador envia automaticamente. El sync worker y Postman siguen usando
 * Bearer via curl/scripts fuera de este cliente.
 *
 * El header `X-Tenant` se inyecta automaticamente desde el store (vía el
 * interceptor del cliente), asi que aqui tampoco lo pasamos manualmente
 * excepto para login/platform-login donde el tenant no esta en el store
 * todavia.
 *
 * Ver docs/AUTH_COOKIE_API.md para el contrato completo.
 */
import { postOne, getOne } from '@/api/client';
import type {
  LoginResponse,
  TenantLookupResponse,
  UserSession,
} from '@/types/user';

export interface TenantLookupRequest {
  email: string;
}

export interface LoginRequest {
  email: string;
  password: string;
  device_name?: string;
}

/** POST /api/auth/tenants — Lista las empresas donde el email está activo. */
export function lookupTenants(payload: TenantLookupRequest) {
  return postOne<TenantLookupRequest, TenantLookupResponse['data']>(
    '/auth/tenants',
    payload,
  );
}

/** POST /api/auth/switch-tenant — Cambia el tenant activo del usuario. */
export function switchTenantApi(slug: string) {
  return postOne<{ tenant_slug: string }, { expires_at: string; user: { id: number; name: string; email: string; is_platform_admin?: boolean }; tenant: { id: number; name: string; slug: string; is_active?: boolean }; roles: (string | { name: string })[]; permissions: string[]; scope_status: 'allow' | 'deny' | 'restrict' | 'none'; scopes: unknown }>(
    '/auth/switch-tenant',
    { tenant_slug: slug },
  );
}

/**
 * POST /api/auth/login — Inicia sesion.
 *
 * El backend emite cookie httpOnly `auth_token` automaticamente cuando el
 * request parece SPA (X-Requested-With: XMLHttpRequest + sin Authorization
 * Bearer). Ver docs/AUTH_COOKIE_API.md seccion "Cuando el backend emite cookie".
 *
 * El header X-Tenant es obligatorio en login (vía TenantManager::require()
 * en el controller), asi que lo pasamos manualmente hasta que el store
 * se hidrate.
 */
export function login(slug: string, payload: LoginRequest) {
  // Debug: ayuda a diagnosticar "tenant not found" cuando el slug esta mal.
  console.warn('[AUTH] login() called with X-Tenant:', slug, 'email:', payload.email);
  return postOne<LoginRequest, LoginResponse['data']>('/auth/login', payload, {
    headers: { 'X-Tenant': slug },
  });
}

/** POST /api/auth/logout — Cierra la sesion actual (revoca el token + limpia cookie). */
export function logout() {
  return postOne<Record<string, never>, { message: string }>('/auth/logout', {});
}

/** GET /api/auth/me — Devuelve la sesion actual (user, tenant, roles, permissions, scopes). */
export function me() {
  return getOne<UserSession>('/auth/me');
}

/** POST /api/auth/switch-tenant — Cambia de empresa activa (rota cookie + emite nuevo token). */
export function switchTenant(slug: string, device_name?: string) {
  return postOne<{ slug: string; device_name?: string }, LoginResponse['data']>(
    '/auth/switch-tenant',
    { slug, device_name },
  );
}
/**
 * Endpoints de autenticacion.
 * Reflejan los endpoints del backend documentados en docs/API.md.
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

/**
 * POST /api/auth/login — Inicia sesion.
 * El backend REQUIERE el header `X-Tenant: <slug>` (vía TenantManager::require()
 * en el controller), por eso pasamos el slug del tenant seleccionado.
 */
export function login(slug: string, payload: LoginRequest) {
  return postOne<LoginRequest, LoginResponse['data']>('/auth/login', payload, {
    headers: { 'X-Tenant': slug },
  });
}

/** POST /api/auth/logout — Cierra la sesion actual (revoca el token). */
export function logout() {
  return postOne<Record<string, never>, { message: string }>('/auth/logout', {});
}

/** GET /api/auth/me — Devuelve la sesion actual (user, tenant, roles, permissions, scopes). */
export function me() {
  return getOne<UserSession>('/auth/me');
}

/** POST /api/auth/switch-tenant — Cambia de empresa activa (emite nuevo token). */
export function switchTenant(slug: string, device_name?: string) {
  return postOne<{ slug: string; device_name?: string }, LoginResponse['data']>(
    '/auth/switch-tenant',
    { slug, device_name },
  );
}
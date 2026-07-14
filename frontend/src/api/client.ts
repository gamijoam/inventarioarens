/**
 * Axios client centralizado con:
 * - withCredentials: true para que el navegador envie la cookie httpOnly
 *   `auth_token` automaticamente con cada request (Plan C hibrido).
 * - Header `X-Requested-With: XMLHttpRequest` en cada request (CSRF mitigation
 *   cuando la auth llega via cookie; los formularios HTML nativos no pueden
 *   setear este header).
 * - NO inyecta `Authorization: Bearer` header. El token vive en la cookie
 *   httpOnly; el sync worker y Postman siguen usando Bearer pero NO pasan
 *   por este cliente (consumen la API directamente con curl/scripts).
 * - 401 handler: callback registrado que llama router.navigate({ to: '/login' })
 *   via SPA navigation. NO usa window.location.href para no perder cache de
 *   TanStack Query.
 *
 * Ver docs/AUTH_COOKIE_API.md para el contrato completo.
 */
import axios, {
  type AxiosError,
  type AxiosRequestConfig,
  type InternalAxiosRequestConfig,
} from 'axios';
import { toast } from 'sonner';

import {
  ForbiddenError,
  HttpError,
  ValidationError,
  type ApiErrorBody,
  type ApiResponse,
  type Paginated,
} from '@/types/api';
import { useSessionStore } from '@/stores/session';

const API_BASE_URL: string = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api';

// Handler externo para 401 (registrado desde main.tsx para tener acceso
// al router context de TanStack). NO usamos window.location.href porque
// eso fuerza un full reload que pierde el cache de TanStack Query.
type UnauthorizedHandler = () => void;
let onUnauthorized: UnauthorizedHandler | null = null;

export function registerUnauthorizedHandler(handler: UnauthorizedHandler): void {
  onUnauthorized = handler;
}

export const api = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30_000,
  // CRITICO: enviar cookies en requests (incluso en cross-origin via Vite proxy).
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    // CRITICO: el backend exige este header para requests autenticados via
    // cookie (mitigacion CSRF). Los formularios HTML nativos no pueden
    // setear este header, asi que ataques CSRF via form-submit fallan.
    'X-Requested-With': 'XMLHttpRequest',
  },
});

// Request interceptor: ya NO inyecta Authorization Bearer (el token vive
// en la cookie httpOnly que el navegador envia automaticamente).
// Solo inyecta X-Tenant si hay un tenant activo en el store y no viene
// ya en los headers (para evitar duplicados).
api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const tenant = useSessionStore.getState().tenant;
  if (tenant?.slug && !config.headers['X-Tenant']) {
    config.headers['X-Tenant'] = tenant.slug;
  }
  return config;
});

// Response interceptor: transforma errores del backend a tipos tipados.
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorBody>) => {
    const status = error.response?.status ?? 0;
    const body = error.response?.data;

    switch (status) {
      case 401: {
        // Token expirado o cookie revocada. El backend puede haber limpiado
        // la cookie via Set-Cookie (logout) o estar pendiente de hacerlo.
        // Limpiamos el store local y delegamos al handler externo la
        // navegacion a /login (SPA, no full reload).
        useSessionStore.getState().clearSession();
        onUnauthorized?.();
        toast.error('Tu sesión expiró. Vuelve a iniciar sesión.');
        break;
      }
      case 403: {
        toast.error(body?.message ?? 'No tienes permiso para esta acción.');
        throw new ForbiddenError(body?.message);
      }
      case 404: {
        toast.error(body?.message ?? 'Recurso no encontrado.');
        break;
      }
      case 422: {
        const fieldErrors = body?.errors ?? {};
        throw new ValidationError(body?.message ?? 'Datos inválidos.', fieldErrors);
      }
      case 500:
      case 502:
      case 503: {
        toast.error('Error del servidor. Por favor intenta de nuevo.');
        break;
      }
      default: {
        if (!status) {
          toast.error('Error de red. Verifica tu conexión.');
        }
      }
    }

    if (status >= 400) {
      throw new HttpError(status, body?.message ?? error.message, body);
    }
    throw error;
  },
);

/* ============================================================================
 * Helpers de uso comun
 * ============================================================================ */

export async function getOne<T>(path: string, config?: AxiosRequestConfig): Promise<T> {
  const response = await api.get<ApiResponse<T>>(path, config);
  return response.data.data;
}

export async function getMany<T>(path: string, config?: AxiosRequestConfig): Promise<T[]> {
  const response = await api.get<ApiResponse<T[]>>(path, config);
  return response.data.data;
}

export async function getPaginated<T>(
  path: string,
  config?: AxiosRequestConfig,
): Promise<Paginated<T>> {
  const response = await api.get<Paginated<T>>(path, config);
  return response.data;
}

export async function postOne<TBody, TResponse = TBody>(
  path: string,
  body?: TBody,
  config?: AxiosRequestConfig,
): Promise<TResponse> {
  const response = await api.post<ApiResponse<TResponse>>(path, body, config);
  return response.data.data;
}

export async function patchOne<TBody, TResponse = TBody>(
  path: string,
  body?: TBody,
  config?: AxiosRequestConfig,
): Promise<TResponse> {
  const response = await api.patch<ApiResponse<TResponse>>(path, body, config);
  return response.data.data;
}

export async function putOne<TBody, TResponse = TBody>(
  path: string,
  body?: TBody,
  config?: AxiosRequestConfig,
): Promise<TResponse> {
  const response = await api.put<ApiResponse<TResponse>>(path, body, config);
  return response.data.data;
}

export async function deleteOne(path: string, config?: AxiosRequestConfig): Promise<void> {
  await api.delete(path, config);
}
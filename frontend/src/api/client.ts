/**
 * Axios client centralizado con:
 * - Inyeccion automatica de Authorization: Bearer <token> + X-Tenant: <slug>.
 * - Manejo centralizado de 401 (logout + redirect) y 403 (toast).
 * - Transformacion de errores del backend a tipos tipados (HttpError, ValidationError).
 *
 * Ver docs/FRONTEND_PERMISSIONS.md §8 para el diseno completo.
 */
import axios, { type AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios';
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

let onUnauthorized: (() => void) | null = null;

/**
 * Permite registrar un handler externo para cuando el backend responde 401.
 * El handler se registra desde el Shell de la app y se ocupa de redirigir a /login.
 */
export function registerUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler;
}

export const api = axios.create({
  baseURL: API_BASE_URL,
  timeout: 30_000,
  headers: { Accept: 'application/json' as const },
});

// Request interceptor: inyecta Bearer + X-Tenant desde el session store.
api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const { token, tenant } = useSessionStore.getState();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  if (tenant?.slug) {
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

/** GET que retorna el shape envuelto { data: T } del backend. */
export async function getOne<T>(path: string, config?: AxiosRequestConfig): Promise<T> {
  const response = await api.get<ApiResponse<T>>(path, config);
  return response.data.data;
}

/** GET que retorna un array del backend (cuando no viene paginado). */
export async function getMany<T>(path: string, config?: AxiosRequestConfig): Promise<T[]> {
  const response = await api.get<ApiResponse<T[]>>(path, config);
  return response.data.data;
}

/** GET que retorna un Paginated<T> del backend. */
export async function getPaginated<T>(
  path: string,
  config?: AxiosRequestConfig,
): Promise<Paginated<T>> {
  const response = await api.get<Paginated<T>>(path, config);
  return response.data;
}

/** POST que retorna { data: T } del backend. */
export async function postOne<TBody, TResponse = TBody>(
  path: string,
  body?: TBody,
  config?: AxiosRequestConfig,
): Promise<TResponse> {
  const response = await api.post<ApiResponse<TResponse>>(path, body, config);
  return response.data.data;
}

/** PATCH que retorna { data: T }. */
export async function patchOne<TBody, TResponse = TBody>(
  path: string,
  body?: TBody,
  config?: AxiosRequestConfig,
): Promise<TResponse> {
  const response = await api.patch<ApiResponse<TResponse>>(path, body, config);
  return response.data.data;
}

/** PUT que retorna { data: T }. */
export async function putOne<TBody, TResponse = TBody>(
  path: string,
  body?: TBody,
  config?: AxiosRequestConfig,
): Promise<TResponse> {
  const response = await api.put<ApiResponse<TResponse>>(path, body, config);
  return response.data.data;
}

/** DELETE sin body. */
export async function deleteOne(path: string, config?: AxiosRequestConfig): Promise<void> {
  await api.delete(path, config);
}
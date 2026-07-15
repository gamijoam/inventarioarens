/**
 * Hook que devuelve la URL base del API para construir links directos
 * a endpoints del backend (ej. descarga de PDF de guia).
 *
 * Usa la config VITE_API_BASE_URL del build si esta definida, sino
 * deriva del window.location (mismo origin).
 */
import { useMemo } from 'react';

export function useTransferApiBaseUrl(): string {
  return useMemo(() => {
    const env = (import.meta as unknown as { env?: Record<string, string> }).env?.VITE_API_BASE_URL;
    if (env && env.length > 0) return env.replace(/\/+$/, '');
    if (typeof window !== 'undefined' && window.location?.origin) {
      return `${window.location.origin}/api`;
    }
    return '/api';
  }, []);
}

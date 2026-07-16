/**
 * API cross-tenant para listar usuarios de un grupo + sus spinoffs.
 *
 * Usado por el Owner de un grupo para ver TODOS los usuarios de su
 * organizacion (no solo los del tenant actual). El backend valida que
 * el user sea Owner del grupo antes de retornar datos.
 *
 * Endpoints:
 *   GET /api/tenants/{tenant}/users?scope=organization
 */
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';

import { getPaginated } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import { type UserListFilters, type UserListResponse } from './schemas';

/**
 * Lista usuarios de toda la organizacion (grupo + spinoffs).
 * Solo Owners del grupo pueden llamar este endpoint sin 403.
 */
export function useCrossTenantUsers(filters: UserListFilters) {
  const tenantId = useSessionStore((s) => s.tenant?.id);
  return useQuery({
    queryKey: ['cross-tenant-users', tenantId, filters],
    queryFn: async (): Promise<UserListResponse> => {
      if (!tenantId) throw new Error('Sin tenant activo.');
      const params = new URLSearchParams();
      params.set('scope', 'organization');
      for (const [k, v] of Object.entries(filters)) {
        if (v == null || v === '' || v === 'all' || k === 'scope') continue;
        params.set(k, String(v));
      }
      const q = params.toString();
      const raw = await getPaginated<unknown>(`/tenants/${tenantId}/users${q ? `?${q}` : ''}`);
      // El endpoint retorna el mismo shape que /api/users (paginated).
      const UserListResponseSchema = z.object({
        data: z.array(z.unknown()),
        meta: z.object({
          current_page: z.number(),
          last_page: z.number(),
          per_page: z.number(),
          total: z.number(),
          from: z.number().nullable(),
          to: z.number().nullable(),
        }),
      });
      const parsed = UserListResponseSchema.safeParse(raw);
      if (!parsed.success) {
        console.warn('[useCrossTenantUsers] shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data as unknown as UserListResponse;
    },
  });
}
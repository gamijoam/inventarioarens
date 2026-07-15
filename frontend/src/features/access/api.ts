/**
 * API minima de Roles (solo lo necesario para los dialogs de Fase B
 * del modulo de usuarios). El modulo `access` completo (CRUD de roles,
 * catalogo de permisos, etc.) se implementa en Fase C.
 *
 * Endpoints backend:
 *   GET    /api/roles  -> listado paginado de roles del tenant actual.
 */
import { useQuery } from '@tanstack/react-query';

import { getPaginated } from '@/api/client';
import { z } from 'zod';

export const RoleSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  is_protected: z.boolean().optional(),
  permissions: z.array(z.string()).optional(),
});
export type Role = z.infer<typeof RoleSchema>;

export const RoleListResponseSchema = z.object({
  data: z.array(RoleSchema),
  meta: z.object({
    current_page: z.number().int(),
    last_page: z.number().int(),
    per_page: z.number().int(),
    total: z.number().int(),
  }).passthrough(),
});
export type RoleListResponse = z.infer<typeof RoleListResponseSchema>;

export const roleKeys = {
  all: ['roles'] as const,
  lists: () => [...roleKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...roleKeys.lists(), filters] as const,
};

/**
 * Listado de roles del tenant actual (paginado, sin filtros por ahora).
 * Devuelve TODOS los roles disponibles para asignar a un user nuevo.
 */
export function useRoles() {
  return useQuery({
    queryKey: roleKeys.list({}),
    queryFn: async () => {
      const data = await getPaginated<unknown>('/roles?per_page=100');
      const parsed = RoleListResponseSchema.safeParse(data);
      if (!parsed.success) {
        console.warn('useRoles: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    staleTime: 5 * 60 * 1000,
  });
}
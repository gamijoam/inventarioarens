/**
 * API del modulo de Acceso (Fase C): roles + catalogo de permisos.
 *
 * Endpoints backend:
 *   GET    /api/roles                                -> listado paginado
 *   POST   /api/roles                                -> crear
 *   GET    /api/roles/{id}                           -> detalle
 *   PATCH  /api/roles/{id}                           -> actualizar nombre
 *   DELETE /api/roles/{id}                           -> eliminar
 *   PATCH  /api/roles/{id}/permissions               -> reemplazar permisos
 *   POST   /api/roles/{id}/duplicate                 -> clonar (body: { name })
 *   GET    /api/roles/{id}/preview                   -> metadata
 *   GET    /api/access/permission-catalog            -> arbol jerarquico
 *
 * Reglas del backend:
 *   - 6 roles base (Owner, Administrador, Gerente, Vendedor, Almacen, Auditor)
 *     no se pueden editar (nombre) ni eliminar.
 *   - Se pueden CLONAR para crear variantes custom.
 *   - El ultimo admin activo del tenant no se puede inactivar.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { getOne, getPaginated, postOne, patchOne, deleteOne } from '@/api/client';

// =====================================================================
// Schemas de Roles
// =====================================================================

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

export const RoleListFiltersSchema = z.object({
  search: z.string().default(''),
  page: z.coerce.number().int().min(1).default(1),
  per_page: z.coerce.number().int().min(1).max(100).default(25),
});
export type RoleListFilters = z.input<typeof RoleListFiltersSchema>;

export const CreateRoleInputSchema = z.object({
  name: z.string().min(1, 'Requerido.').max(150),
  permissions: z.array(z.string()).default([]),
});
export type CreateRoleInput = z.input<typeof CreateRoleInputSchema>;

export const UpdateRoleInputSchema = z.object({
  name: z.string().min(1, 'Requerido.').max(150),
});
export type UpdateRoleInput = z.input<typeof UpdateRoleInputSchema>;

export const UpdateRolePermissionsInputSchema = z.object({
  permissions: z.array(z.string()),
});
export type UpdateRolePermissionsInput = z.input<typeof UpdateRolePermissionsInputSchema>;

export const DuplicateRoleInputSchema = z.object({
  name: z.string().min(1, 'Requerido.').max(150),
});
export type DuplicateRoleInput = z.input<typeof DuplicateRoleInputSchema>;

// =====================================================================
// Schemas del catalogo de permisos
// =====================================================================

export const PermissionActionSchema = z.object({
  verb: z.string(),
  label: z.string(),
  permission: z.string(),
  danger: z.enum(['high', 'medium']).optional(),
});
export type PermissionAction = z.infer<typeof PermissionActionSchema>;

export const PermissionModuleSchema = z.object({
  module: z.string(),
  label: z.string(),
  verb_count: z.number().int(),
  actions: z.array(PermissionActionSchema),
});
export type PermissionModule = z.infer<typeof PermissionModuleSchema>;

export const PermissionCatalogSchema = z.object({
  modules: z.array(PermissionModuleSchema),
  verbs: z.array(z.object({ name: z.string(), label: z.string() })),
  total_permissions: z.number().int(),
  total_modules: z.number().int(),
});
export type PermissionCatalog = z.infer<typeof PermissionCatalogSchema>;

// =====================================================================
// Query keys
// =====================================================================

export const roleKeys = {
  all: ['roles'] as const,
  lists: () => [...roleKeys.all, 'list'] as const,
  list: (filters: Record<string, unknown>) => [...roleKeys.lists(), filters] as const,
  details: () => [...roleKeys.all, 'detail'] as const,
  detail: (id: number) => [...roleKeys.details(), id] as const,
  previews: () => [...roleKeys.all, 'preview'] as const,
  preview: (id: number) => [...roleKeys.previews(), id] as const,
};

export const permissionCatalogKey = ['permissions', 'catalog'] as const;

// =====================================================================
// Roles: queries
// =====================================================================

export function useRoles(filters: RoleListFilters = { search: '', page: 1, per_page: 25 }) {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v == null || v === '') continue;
    params.set(k, String(v));
  }
  const qs = params.toString();
  return useQuery({
    queryKey: roleKeys.list(filters as Record<string, unknown>),
    queryFn: async () => {
      const data = await getPaginated<unknown>(`/roles${qs ? '?' + qs : ''}`);
      const parsed = RoleListResponseSchema.safeParse(data);
      if (!parsed.success) {
        console.warn('useRoles: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    placeholderData: (prev) => prev,
  });
}

export function useRole(id: number) {
  return useQuery({
    queryKey: roleKeys.detail(id),
    queryFn: async () => {
      const data = await getOne<unknown>(`/roles/${id}`);
      const parsed = RoleSchema.safeParse(data);
      if (!parsed.success) {
        console.warn('useRole: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

export function useRolePreview(id: number) {
  return useQuery({
    queryKey: roleKeys.preview(id),
    queryFn: async () => {
      const raw = await getOne<unknown>(`/roles/${id}/preview`);
      // El preview retorna un shape custom. Devolvemos raw tipado minimo.
      return raw as { data: { role_id: number; name: string; permission_count: number; module_count: number; modules: string[]; wildcards_count: number; protected: boolean } };
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

// =====================================================================
// Roles: mutations
// =====================================================================

export function useCreateRole() {
  const qc = useQueryClient();
  return useMutation<Role, Error, CreateRoleInput>({
    mutationFn: (values) => postOne<CreateRoleInput, Role>('/roles', values),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: roleKeys.lists() });
    },
  });
}

export function useUpdateRole() {
  const qc = useQueryClient();
  return useMutation<Role, Error, { id: number; values: UpdateRoleInput }>({
    mutationFn: ({ id, values }) => patchOne<UpdateRoleInput, Role>(`/roles/${id}`, values),
    onSuccess: async (_data, { id }) => {
      await qc.invalidateQueries({ queryKey: roleKeys.lists() });
      await qc.invalidateQueries({ queryKey: roleKeys.detail(id) });
    },
  });
}

export function useDeleteRole() {
  const qc = useQueryClient();
  return useMutation<void, Error, number>({
    mutationFn: (id) => deleteOne(`/roles/${id}`).then(() => undefined),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: roleKeys.lists() });
    },
  });
}

export function useUpdateRolePermissions() {
  const qc = useQueryClient();
  return useMutation<Role, Error, { id: number; values: UpdateRolePermissionsInput }>({
    mutationFn: ({ id, values }) =>
      patchOne<UpdateRolePermissionsInput, Role>(`/roles/${id}/permissions`, values),
    onSuccess: async (_data, { id }) => {
      await qc.invalidateQueries({ queryKey: roleKeys.lists() });
      await qc.invalidateQueries({ queryKey: roleKeys.detail(id) });
    },
  });
}

export function useDuplicateRole() {
  const qc = useQueryClient();
  return useMutation<Role, Error, { id: number; values: DuplicateRoleInput }>({
    mutationFn: ({ id, values }) => postOne<DuplicateRoleInput, Role>(`/roles/${id}/duplicate`, values),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: roleKeys.lists() });
    },
  });
}

// =====================================================================
// Permisos: catalogo
// =====================================================================

export function usePermissionCatalog() {
  return useQuery({
    queryKey: permissionCatalogKey,
    queryFn: async () => {
      const data = await getOne<unknown>('/access/permission-catalog');
      // El controller envuelve en { data: ... }
      const inner = (data as { data?: unknown }).data ?? data;
      const parsed = PermissionCatalogSchema.safeParse(inner);
      if (!parsed.success) {
        console.warn('usePermissionCatalog: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    staleTime: 30 * 60 * 1000, // 30 min: el catalogo cambia rara vez.
  });
}
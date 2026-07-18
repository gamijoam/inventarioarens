/**
 * API del modulo de Usuarios (TenantUser).
 *
 * Endpoints backend:
 *   GET    /api/users                        -> listado paginado (TanStack Query)
 *   GET    /api/users/{id}                   -> detalle (Fase B)
 *   POST   /api/users                        -> crear (Fase B)
 *   PATCH  /api/users/{id}                   -> actualizar nombre (Fase B)
 *   PATCH  /api/users/{id}/status            -> activar/inactivar (Fase B)
 *   PATCH  /api/users/{id}/roles             -> asignar roles (Fase B)
 *
 * Esta Fase A implementa solo useUsers. Las mutations quedan
 * documentadas pero las clases reales se harn en Fase B.
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

import { getOne, getPaginated, postOne, patchOne, putOne } from '@/api/client';
import {
  UserListResponseSchema,
  type CreateUserInput,
  type UpdateUserInput,
  UserSchema,
  type UpdateUserRolesInput,
  type UpdateUserStatusInput,
  type UserListFilters,
  type User,
} from './schemas';

export type { User, UserListFilters } from './schemas';

export const userKeys = {
  all: ['users'] as const,
  lists: () => [...userKeys.all, 'list'] as const,
  list: (filters: UserListFilters) => [...userKeys.lists(), filters] as const,
  details: () => [...userKeys.all, 'detail'] as const,
  detail: (id: number, scope: UserListFilters['scope'] = 'tenant') => [...userKeys.details(), id, scope] as const,
};

function toQueryString(filters: UserListFilters): string {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) {
    if (v == null || v === '' || v === 'all') continue;
    params.set(k, String(v));
  }
  const q = params.toString();
  return q ? `?${q}` : '';
}

/**
 * Listado paginado de usuarios del tenant actual.
 * El backend ya filtra por tenant via middleware; el parametro `role_id`
 * es opcional y filtra usuarios que tengan ese rol asignado.
 */
export function useUsers(filters: UserListFilters) {
  return useQuery({
    queryKey: userKeys.list(filters),
    queryFn: async () => {
      const data = await getPaginated<unknown>(`/users${toQueryString(filters)}`);
      const parsed = UserListResponseSchema.safeParse(data);
      if (!parsed.success) {
        console.warn('[useUsers] shape invalido, issues:', JSON.stringify(parsed.error.issues, null, 2));
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    placeholderData: (prev) => prev,
  });
}

/**
 * (Fase B) Crea un usuario y lo vincula al tenant actual con los roles dados.
 */
export function useCreateUser() {
  const qc = useQueryClient();
  return useMutation<User, Error, CreateUserInput>({
    mutationFn: (values) => postOne<CreateUserInput, User>('/users', values),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: userKeys.lists() });
    },
  });
}

/**
 * Detalle de un usuario (GET /api/users/{id}).
 */
export function useUser(id: number, scope: UserListFilters['scope'] = 'tenant') {
  return useQuery({
    queryKey: userKeys.detail(id, scope),
    queryFn: async () => {
      const data = await getOne<unknown>(`/users/${id}${scope === 'organization' ? '?scope=organization' : ''}`);
      const parsed = UserSchema.safeParse(data);
      if (!parsed.success) {
        console.warn('useUser: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    enabled: Number.isFinite(id) && id > 0,
  });
}

/**
 * (Fase B) Actualiza el nombre del usuario.
 */
export function useUpdateUser() {
  const qc = useQueryClient();
  return useMutation<User, Error, { id: number; values: UpdateUserInput }>({
    mutationFn: ({ id, values }) =>
      patchOne<UpdateUserInput, User>(`/users/${id}`, values),
    onSuccess: async (_data, { id }) => {
      await qc.invalidateQueries({ queryKey: userKeys.lists() });
      await qc.invalidateQueries({ queryKey: userKeys.detail(id) });
    },
  });
}

/**
 * (Fase B) Asigna los roles del usuario en el tenant actual.
 */
export function useUpdateUserRoles() {
  const qc = useQueryClient();
  return useMutation<User, Error, { id: number; values: UpdateUserRolesInput }>({
    mutationFn: ({ id, values }) =>
      patchOne<UpdateUserRolesInput, User>(`/users/${id}/roles`, values),
    onSuccess: async (_data, { id }) => {
      await qc.invalidateQueries({ queryKey: userKeys.lists() });
      await qc.invalidateQueries({ queryKey: userKeys.detail(id) });
    },
  });
}

/**
 * (Fase B) Activa o inactiva el usuario en el tenant actual.
 */
export function useUpdateUserStatus() {
  const qc = useQueryClient();
  return useMutation<User, Error, { id: number; values: UpdateUserStatusInput }>({
    mutationFn: ({ id, values }) =>
      postOne<UpdateUserStatusInput, User>(`/users/${id}/status`, values),
    onSuccess: async (_data, { id }) => {
      await qc.invalidateQueries({ queryKey: userKeys.lists() });
      await qc.invalidateQueries({ queryKey: userKeys.detail(id) });
    },
  });
}

// =====================================================================
// Fase D: Overrides y Scopes por usuario.
// Las rutas del backend son cross-tenant (path = /tenants/{tenant}/...),
// por lo que el frontend pasa el tenant_id actual del store.
// =====================================================================

import { z } from 'zod';
import { deleteOne } from '@/api/client';
import { useSessionStore } from '@/stores/session';

function tenantPath(tenantId: number, path: string): string {
  return `/tenants/${tenantId}${path}`;
}

// Overrides

export const UserOverrideSchema = z.object({
  permission: z.string(),
  effect: z.enum(['allow', 'deny']),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
});
export type UserOverride = z.infer<typeof UserOverrideSchema>;

export const UserOverridesResponseSchema = z.object({
  user_id: z.number().int(),
  tenant_id: z.number().int(),
  items: z.array(UserOverrideSchema),
  extra_count: z.number().int(),
  deny_count: z.number().int(),
  extras: z.array(z.string()),
  denied: z.array(z.string()),
});
export type UserOverridesResponse = z.infer<typeof UserOverridesResponseSchema>;

export const ReplaceOverridesInputSchema = z.object({
  items: z.array(z.object({
    permission: z.string(),
    effect: z.enum(['allow', 'deny']),
  })),
});
export type ReplaceOverridesInput = z.input<typeof ReplaceOverridesInputSchema>;

export const userOverrideKeys = {
  all: ['user-overrides'] as const,
  list: (tenantId: number, userId: number) => [...userOverrideKeys.all, tenantId, userId] as const,
};

export function useUserOverrides(userId: number) {
  const tenantId = useSessionStore((s) => s.tenant?.id);
  return useQuery({
    queryKey: tenantId ? userOverrideKeys.list(tenantId, userId) : userOverrideKeys.all,
    queryFn: async () => {
      if (!tenantId) throw new Error('Sin tenant activo.');
      const raw = await getOne<unknown>(tenantPath(tenantId, `/users/${userId}/overrides`));
      const parsed = UserOverridesResponseSchema.safeParse(raw);
      if (!parsed.success) {
        console.warn('useUserOverrides: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    enabled: Number.isFinite(userId) && userId > 0,
  });
}

export function useReplaceUserOverrides() {
  const qc = useQueryClient();
  return useMutation<UserOverridesResponse, Error, { userId: number; values: ReplaceOverridesInput }>({
    mutationFn: async ({ userId, values }) => {
      const tenantId = useSessionStore.getState().tenant?.id;
      if (!tenantId) throw new Error('Sin tenant activo.');
      return await putOne<ReplaceOverridesInput, UserOverridesResponse>(
        tenantPath(tenantId, `/users/${userId}/overrides`),
        values,
      );
    },
    onSuccess: async (_data, { userId }) => {
      const tenantId = useSessionStore.getState().tenant?.id;
      if (tenantId) await qc.invalidateQueries({ queryKey: userOverrideKeys.list(tenantId, userId) });
    },
  });
}

export function useRemoveUserOverride() {
  const qc = useQueryClient();
  return useMutation<void, Error, { userId: number; permission: string }>({
    mutationFn: async ({ userId, permission }) => {
      const tenantId = useSessionStore.getState().tenant?.id;
      if (!tenantId) throw new Error('Sin tenant activo.');
      await deleteOne(tenantPath(tenantId, `/users/${userId}/overrides/${encodeURIComponent(permission)}`));
    },
    onSuccess: async (_data, { userId }) => {
      const tenantId = useSessionStore.getState().tenant?.id;
      if (tenantId) await qc.invalidateQueries({ queryKey: userOverrideKeys.list(tenantId, userId) });
    },
  });
}

// Effective permissions

export const EffectivePermissionsResponseSchema = z.object({
  user_id: z.number().int(),
  permissions: z.array(z.string()),
  permission_count: z.number().int(),
  base_permissions: z.array(z.string()),
  base_count: z.number().int(),
  extras: z.array(z.string()),
  denied: z.array(z.string()),
  roles: z.array(z.string()),
  scope_status: z.string(),
  scopes: z.unknown(),
});
export type EffectivePermissionsResponse = z.infer<typeof EffectivePermissionsResponseSchema>;

export function useEffectivePermissions(userId: number) {
  const tenantId = useSessionStore((s) => s.tenant?.id);
  return useQuery({
    queryKey: tenantId
      ? [...userOverrideKeys.all, 'effective', tenantId, userId]
      : userOverrideKeys.all,
    queryFn: async () => {
      if (!tenantId) throw new Error('Sin tenant activo.');
      const raw = await getOne<unknown>(
        tenantPath(tenantId, `/users/${userId}/effective-permissions`),
      );
      const parsed = EffectivePermissionsResponseSchema.safeParse(raw);
      if (!parsed.success) {
        console.warn('useEffectivePermissions: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    enabled: Number.isFinite(userId) && userId > 0,
  });
}

// Scopes

export const UserScopesResponseSchema = z.object({
  user_id: z.number().int(),
  tenant_id: z.number().int(),
  scope_status: z.string(),
  branches: z.array(z.number()).optional(),
  warehouses: z.array(z.number()).optional(),
  customer_groups: z.array(z.number()).optional(),
  vendor_of: z.array(z.number()).optional(),
  branches_count: z.number().int().optional(),
  warehouses_count: z.number().int().optional(),
  customer_groups_count: z.number().int().optional(),
  vendor_of_count: z.number().int().optional(),
}).passthrough();
export type UserScopesResponse = z.infer<typeof UserScopesResponseSchema>;

export const ReplaceAllScopesInputSchema = z.object({
  branches: z.array(z.number()).default([]),
  warehouses: z.array(z.number()).default([]),
  customer_groups: z.array(z.number()).default([]),
  vendor_of: z.array(z.number()).default([]),
});
export type ReplaceAllScopesInput = z.input<typeof ReplaceAllScopesInputSchema>;

export const userScopeKeys = {
  all: ['user-scopes'] as const,
  detail: (tenantId: number, userId: number) => [...userScopeKeys.all, tenantId, userId] as const,
};

export function useUserScopes(userId: number) {
  const tenantId = useSessionStore((s) => s.tenant?.id);
  return useQuery({
    queryKey: tenantId ? userScopeKeys.detail(tenantId, userId) : userScopeKeys.all,
    queryFn: async () => {
      if (!tenantId) throw new Error('Sin tenant activo.');
      const raw = await getOne<unknown>(tenantPath(tenantId, `/users/${userId}/scopes`));
      const parsed = UserScopesResponseSchema.safeParse(raw);
      if (!parsed.success) {
        console.warn('useUserScopes: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    enabled: Number.isFinite(userId) && userId > 0,
  });
}

export function useReplaceAllScopes() {
  const qc = useQueryClient();
  return useMutation<UserScopesResponse, Error, { userId: number; values: ReplaceAllScopesInput }>({
    mutationFn: async ({ userId, values }) => {
      const tenantId = useSessionStore.getState().tenant?.id;
      if (!tenantId) throw new Error('Sin tenant activo.');
      return await putOne<ReplaceAllScopesInput, UserScopesResponse>(
        tenantPath(tenantId, `/users/${userId}/scopes`),
        values,
      );
    },
    onSuccess: async (_data, { userId }) => {
      const tenantId = useSessionStore.getState().tenant?.id;
      if (tenantId) await qc.invalidateQueries({ queryKey: userScopeKeys.detail(tenantId, userId) });
    },
  });
}

// Audit

export const UserAuditEntrySchema = z.object({
  id: z.number().int(),
  action: z.string(),
  entity_type: z.string(),
  entity_id: z.number().int(),
  old_values: z.record(z.string(), z.unknown()).nullable().optional(),
  new_values: z.record(z.string(), z.unknown()).nullable().optional(),
  user_id: z.number().int().nullable().optional(),
  ip_address: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
});
export type UserAuditEntry = z.infer<typeof UserAuditEntrySchema>;

export const UserAuditsResponseSchema = z.object({
  data: z.array(UserAuditEntrySchema),
  total: z.number().int(),
});
export type UserAuditsResponse = z.infer<typeof UserAuditsResponseSchema>;

export const userAuditKeys = {
  all: ['user-audits'] as const,
  list: (tenantId: number, userId: number) => [...userAuditKeys.all, tenantId, userId] as const,
};

export function useUserAudits(userId: number) {
  const tenantId = useSessionStore((s) => s.tenant?.id);
  return useQuery({
    queryKey: tenantId ? userAuditKeys.list(tenantId, userId) : userAuditKeys.all,
    queryFn: async () => {
      if (!tenantId) throw new Error('Sin tenant activo.');
      const data = await getOne<unknown>(
        tenantPath(tenantId, `/users/${userId}/audits`),
      );
      const parsed = UserAuditsResponseSchema.safeParse(data);
      if (!parsed.success) {
        console.warn('useUserAudits: shape invalido', parsed.error.flatten());
        throw new Error('Respuesta del servidor invalida.');
      }
      return parsed.data;
    },
    enabled: Number.isFinite(userId) && userId > 0,
  });
}

// Catalogo para pickers (branches/warehouses/customer-groups)

export interface ScopesCatalog {
  branches: { id: number; name: string; code: string }[];
  warehouses: { id: number; name: string; code: string }[];
  customerGroups: { id: number; name: string; code: string }[];
  vendors: { id: number; name: string; tax_id: string | null }[];
}

/** Hook simple que carga catalogs para pickers de scopes. */
export function useScopesCatalog() {
  return useQuery({
    queryKey: ['scope-catalogs'],
    queryFn: async () => {
      // Carga paralela: branches, warehouses, customer-groups.
      const [branchesRes, warehousesRes, customerGroupsRes, suppliersRes] = await Promise.all([
        getOne<unknown>('/branches?per_page=200'),
        getOne<unknown>('/warehouses?per_page=200'),
        getOne<unknown>('/customer-groups?per_page=200'),
        getOne<unknown>('/suppliers?per_page=200'),
      ]);
      // Cada uno retorna { data: [...] } o [...] segun el controller.
      const unwrap = (r: unknown): { id: number; name?: string; code?: string; tax_id?: string | null }[] => {
        if (Array.isArray(r)) return r as never;
        if (r && typeof r === 'object' && 'data' in r) return (r as { data: never[] }).data;
        return [];
      };
      const branches = unwrap(branchesRes).map((b) => ({ id: b.id, name: b.name ?? '', code: b.code ?? '' }));
      const warehouses = unwrap(warehousesRes).map((w) => ({ id: w.id, name: w.name ?? '', code: w.code ?? '' }));
      const customerGroups = unwrap(customerGroupsRes).map((c) => ({ id: c.id, name: c.name ?? '', code: c.code ?? '' }));
      const vendors = unwrap(suppliersRes).map((s) => ({ id: s.id, name: s.name ?? '', tax_id: s.tax_id ?? null }));
      return { branches, warehouses, customerGroups, vendors } satisfies ScopesCatalog;
    },
    staleTime: 5 * 60 * 1000,
  });
}
